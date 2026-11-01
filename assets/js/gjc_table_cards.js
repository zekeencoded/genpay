/* ═══ Responsive table cards (pairs with gjc-table-cards.css).
   On narrow screens rows become cards; columns are declared via data-card on <th>.
   Tapping a card follows the row's link, clicks its View button, or expands it.
   A MutationObserver re-applies after DataTables redraws. Opt out with data-cards="false". ═══ */
(function () {
  "use strict";

  /* Wide tables stack one breakpoint earlier. */
  var WIDE_FROM_COLUMNS = 7;
  var PHONE_QUERY = "(max-width: 767.98px)";
  var TABLET_QUERY = "(max-width: 991.98px)";

  /* Controls that handle their own taps. */
  var INTERACTIVE = "a, button, input, select, textarea, label, .form-check";

  /* What makes a cell the action footer (buttons and links only). */
  var ACTION_CONTENT = "a[href], button, .btn";

  /* Controls the card tap replaces. */
  var VIEW_HINT = /\b(view|details?)\b/i;

  var READY_FLAG = "gjcCards"; // data-gjc-cards on the table element
  var VALUE_CLASS = "gjc-value";

  function textOf(node) {
    return (node.textContent || "").replace(/\s+/g, " ").trim();
  }

  function spanOf(cell) {
    return parseInt(cell.getAttribute("colspan"), 10) || 1;
  }

  /* Header text and role per column, expanding colspans. */
  function readColumns(table) {
    var head = table.tHead;
    if (!head || !head.rows.length) {
      return null;
    }
    var cells = head.rows[head.rows.length - 1].cells;
    var columns = [];
    for (var i = 0; i < cells.length; i++) {
      var column = {
        label: textOf(cells[i]),
        role: cells[i].getAttribute("data-card") || ""
      };
      var span = spanOf(cells[i]);
      for (var s = 0; s < span; s++) {
        columns.push(column);
      }
    }
    return columns;
  }

  /* Wrap cell content in a span (moves nodes, so DataTables search is unaffected). Idempotent. */
  function wrapValue(cell) {
    var only = cell.childNodes.length === 1 ? cell.firstChild : null;
    if (only && only.nodeType === 1 && only.className === VALUE_CLASS) {
      return;
    }
    var span = document.createElement("span");
    span.className = VALUE_CLASS;
    while (cell.firstChild) {
      span.appendChild(cell.firstChild);
    }
    cell.appendChild(span);
  }

  function isViewControl(el) {
    if (el.tagName === "A") {
      return true;
    }
    return VIEW_HINT.test(textOf(el)) || VIEW_HINT.test(el.getAttribute("title") || "");
  }

  /* Tap target: a link href, a control to click, or nothing (expand). */
  function findTarget(row) {
    var link = row.querySelector("a[href]");
    if (link && !/^#|^javascript:/i.test(link.getAttribute("href") || "")) {
      return { kind: "link", el: link };
    }
    var buttons = row.querySelectorAll("button");
    for (var i = 0; i < buttons.length; i++) {
      if (isViewControl(buttons[i])) {
        return { kind: "click", el: buttons[i] };
      }
    }
    return null;
  }

  var PLACEMENTS = [
    "gjc-cell-title",
    "gjc-cell-amount",
    "gjc-cell-meta",
    "gjc-cell-labelled",
    "gjc-cell-hidden"
  ];

  /* Which part of the card a cell becomes. */
  function placementOf(cell, role, list) {
    if (role === "hide") {
      return ["gjc-cell-hidden", "gjc-cell-labelled"];
    }
    if (cell.classList.contains("gjc-cell-actions")) {
      return [];
    }
    if (list && role === "title") {
      return ["gjc-cell-title"];
    }
    if (list && role === "amount") {
      return ["gjc-cell-amount"];
    }
    if (list && !cell.querySelector(INTERACTIVE)) {
      return ["gjc-cell-meta"];
    }
    return ["gjc-cell-labelled"];
  }

  function classify(cell, role, list) {
    var placed = placementOf(cell, role, list);
    for (var i = 0; i < PLACEMENTS.length; i++) {
      cell.classList.toggle(PLACEMENTS[i], placed.indexOf(PLACEMENTS[i]) !== -1);
    }
  }

  function labelRow(row, columns) {
    var cells = row.cells;
    var column = 0;
    var hidden = 0;
    var titled = false;
    var actionCell = null;
    var roles = [];

    for (var i = 0; i < cells.length; i++) {
      var cell = cells[i];
      var span = spanOf(cell);

      /* No label on full-width empty-state rows. */
      if (span > 1 || cell.classList.contains("dataTables_empty")) {
        cell.classList.add("gjc-cell-full");
        cell.removeAttribute("data-label");
        wrapValue(cell);
        column += span;
        continue;
      }

      var spec = columns[column] || { label: "", role: "" };

      /* Keep the label column even when empty. */
      cell.setAttribute("data-label", spec.label);

      if (spec.role === "title") {
        titled = true;
      }
      if (spec.role === "hide") {
        hidden++;
      }

      if (cell.querySelector(ACTION_CONTENT)) {
        cell.classList.add("gjc-cell-actions");
        actionCell = cell;
      } else {
        cell.classList.remove("gjc-cell-actions");
      }

      roles.push({ cell: cell, role: spec.role });
      wrapValue(cell);
      column += span;
    }

    /* Placement needs the whole row (list layout needs a title column). */
    for (var n = 0; n < roles.length; n++) {
      classify(roles[n].cell, roles[n].role, titled);
    }

    row.classList.toggle("gjc-row-list", titled);

    applyTarget(row, actionCell, hidden);
  }

  /* Make the row a link, button or expander, with matching ARIA. */
  function applyTarget(row, actionCell, hidden) {
    var target = findTarget(row);

    row.classList.remove("gjc-row-open", "gjc-row-expand");
    if (actionCell) {
      actionCell.classList.remove("gjc-cell-absorbed");
    }

    if (target) {
      row.gjcTarget = target;
      row.setAttribute("role", target.kind === "link" ? "link" : "button");
      row.setAttribute("tabindex", "0");

      /* Hide the View control when it's the only action. */
      if (actionCell && actionCell.querySelectorAll(ACTION_CONTENT).length === 1) {
        actionCell.classList.add("gjc-cell-absorbed");
      }
      return;
    }

    row.gjcTarget = null;

    if (hidden) {
      row.classList.add("gjc-row-expand");
      row.setAttribute("role", "button");
      row.setAttribute("tabindex", "0");
      row.setAttribute("aria-expanded", "false");
      return;
    }

    /* Nothing to open and nothing hidden: an ordinary card. */
    row.removeAttribute("role");
    row.removeAttribute("tabindex");
    row.removeAttribute("aria-expanded");
  }

  function activate(row) {
    var target = row.gjcTarget;

    if (target && target.kind === "link") {
      window.location.href = target.el.href;
      return;
    }
    if (target && target.kind === "click") {
      target.el.click();
      return;
    }
    if (row.classList.contains("gjc-row-expand")) {
      var open = row.classList.toggle("gjc-row-open");
      row.setAttribute("aria-expanded", open ? "true" : "false");
    }
  }

  /* One delegated listener per table; taps on inner controls are ignored. */
  function bindActivation(table) {
    function handled(event) {
      if (!table.classList.contains("gjc-cards--on")) {
        return null;
      }
      var row = event.target.closest ? event.target.closest("tr") : null;
      if (!row || !row.parentNode || row.parentNode.tagName !== "TBODY") {
        return null;
      }
      if (!row.gjcTarget && !row.classList.contains("gjc-row-expand")) {
        return null;
      }
      if (event.target.closest(INTERACTIVE)) {
        return null;
      }
      return row;
    }

    table.addEventListener("click", function (event) {
      var row = handled(event);
      if (row) {
        activate(row);
      }
    });

    table.addEventListener("keydown", function (event) {
      if (event.key !== "Enter" && event.key !== " " && event.key !== "Spacebar") {
        return;
      }
      var row = handled(event);
      if (row) {
        event.preventDefault();
        activate(row);
      }
    });
  }

  function relabel(table) {
    var columns = table.gjcColumns || readColumns(table);
    if (!columns || !columns.length) {
      return;
    }
    var bodies = table.tBodies;
    for (var b = 0; b < bodies.length; b++) {
      var rows = bodies[b].rows;
      for (var r = 0; r < rows.length; r++) {
        labelRow(rows[r], columns);
      }
    }
  }

  /* Toggle .gjc-cards--on with the viewport. */
  function bindBreakpoint(table, wrap, query) {
    if (!window.matchMedia) {
      return;
    }
    var mq = window.matchMedia(query);

    var sync = function () {
      table.classList.toggle("gjc-cards--on", mq.matches);
      if (wrap) {
        wrap.classList.toggle("gjc-cards-wrap--on", mq.matches);
      }
    };

    sync();
    if (mq.addEventListener) {
      mq.addEventListener("change", sync);
    } else if (mq.addListener) {
      mq.addListener(sync); // Safari < 14
    }
  }

  function setup(table) {
    if (table.dataset[READY_FLAG] === "1" || table.getAttribute("data-cards") === "false") {
      return;
    }

    var columns = readColumns(table);
    /* Nothing to stack. */
    if (!columns || columns.length < 2 || !table.tBodies.length) {
      return;
    }

    table.dataset[READY_FLAG] = "1";
    table.gjcColumns = columns;
    table.classList.add("gjc-cards");

    var wide = columns.length >= WIDE_FROM_COLUMNS;
    if (wide) {
      table.classList.add("gjc-cards--wide");
    }

    relabel(table);
    bindActivation(table);
    bindBreakpoint(
      table,
      table.closest(".table-responsive"),
      wide ? TABLET_QUERY : PHONE_QUERY
    );
  }

  function scan(root) {
    var tables = (root || document).querySelectorAll("table");
    for (var i = 0; i < tables.length; i++) {
      setup(tables[i]);
    }
  }

  function watch() {
    if (typeof MutationObserver === "undefined") {
      return;
    }

    var queued = null;

    var observer = new MutationObserver(function (records) {
      var fresh = [];
      var stale = [];

      for (var i = 0; i < records.length; i++) {
        var added = records[i].addedNodes;
        for (var j = 0; j < added.length; j++) {
          var node = added[j];
          if (node.nodeType !== 1) {
            continue;
          }
          if (node.tagName === "TABLE") {
            fresh.push(node);
          } else if (node.querySelectorAll) {
            var nested = node.querySelectorAll("table");
            for (var k = 0; k < nested.length; k++) {
              fresh.push(nested[k]);
            }
          }
        }

        /* Relabel rows swapped in by redraws. */
        var target = records[i].target;
        var owner = target && target.closest ? target.closest("table.gjc-cards") : null;
        if (owner && stale.indexOf(owner) === -1) {
          stale.push(owner);
        }
      }

      if (!fresh.length && !stale.length) {
        return;
      }

      /* Coalesce: one draw fires a mutation per row. */
      if (queued) {
        window.cancelAnimationFrame(queued);
      }
      queued = window.requestAnimationFrame(function () {
        queued = null;
        for (var a = 0; a < fresh.length; a++) {
          setup(fresh[a]);
        }
        for (var b = 0; b < stale.length; b++) {
          relabel(stale[b]);
        }
        /* Drop our own mutations so the observer doesn't loop. */
        observer.takeRecords();
      });
    });

    observer.observe(document.body, { childList: true, subtree: true });
  }

  function start() {
    scan(document);
    watch();
  }

  /* For tables built outside the observer's reach. */
  window.GjcTableCards = { scan: scan, relabel: relabel };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start);
  } else {
    start();
  }
})();
