/* ============================================================
   GenPay — RESPONSIVE TABLE CARDS (behaviour)
   Pairs with assets/css/gjc-table-cards.css.

   On a narrow screen a listing table stops being a table and
   becomes a list of rows: what the row is on the left of the first
   line, the figure on the right, the supporting detail muted
   underneath, and a tap anywhere opening its full details.

   What each column does is declared on its <th>:
     data-card="title"   first line, left — the row's identity
     data-card="amount"  first line, right — the figure
     data-card="hide"    detail-only; off the row
     (unmarked)          the muted second line, dot-separated

   Declaring a title is what opts a table into that list layout. A
   table that declares none keeps a LABEL / value line per field,
   which is the right presentation when the values do not describe
   themselves — the parent's school-year balances are three money
   columns that would be unreadable stripped of their headings. It
   is also how detail-only fields render when a row expands.

   Tapping a card resolves in this order:
     1. a link in the row (the View button's href) — navigate to it
     2. a "View" button in the row — click it (the merchant order
        queue opens a modal rather than a page)
     3. otherwise expand the card in place to reveal the hidden
        fields, so nothing is unreachable on tables with no detail
        page of their own (top-ups, withdrawals, encashments)

   Progressive enhancement is deliberate: the CSS keys off classes
   only this file adds, so with JS off nothing changes and the
   table keeps scrolling sideways as before.

   Rows that arrive later are covered too — DataTables replaces the
   whole tbody on every draw/search/page, and any
   table built from a template string by a script. A single
   document-level MutationObserver re-applies all of it.

   Opt a table out with data-cards="false".
   ============================================================ */
(function () {
  "use strict";

  /* A table this wide can't fit a tablet either, so it stacks one
     breakpoint earlier than the rest. Mirrored in the stylesheet header. */
  var WIDE_FROM_COLUMNS = 7;
  var PHONE_QUERY = "(max-width: 767.98px)";
  var TABLET_QUERY = "(max-width: 991.98px)";

  /* Anything a tap should reach on its own, rather than being swallowed by
     the card's own tap: the staff roster's Active switch, the inventory
     buttons, the queue's Void. */
  var INTERACTIVE = "a, button, input, select, textarea, label, .form-check";

  /* What makes a cell the card's action footer — buttons and links only.
     Deliberately narrower than INTERACTIVE: the staff roster's Active
     switch is a value on a labelled line, not an action. */
  var ACTION_CONTENT = "a[href], button, .btn";

  /* Controls that mean "show me this row" — the one control the card tap
     replaces. Everything else in an action cell stays on the card. */
  var VIEW_HINT = /\b(view|details?)\b/i;

  var READY_FLAG = "gjcCards"; // data-gjc-cards on the table element
  var VALUE_CLASS = "gjc-value";

  function textOf(node) {
    return (node.textContent || "").replace(/\s+/g, " ").trim();
  }

  function spanOf(cell) {
    return parseInt(cell.getAttribute("colspan"), 10) || 1;
  }

  /* Header text and card role per column index, expanded so a spanning <th>
     covers each of the columns it sits over. */
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

  /* Move the cell's content into a span of its own, so the label and the
     value are two flex items rather than a label overlaid on padding — a
     label long enough to wrap ("Starting Balance") then makes its row
     taller instead of colliding with the label below it. Only moves nodes,
     never rewrites them, so the cell's textContent — what DataTables
     searches and sorts on — is untouched. Idempotent. */
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

  /* What tapping this card should do. Returns a link href, a control to
     click, or nothing — in which case the card expands instead. */
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

  /* Which job a cell does on the card. Anything the list layout's two lines
     do not place falls back to a labelled line of its own: the detail-only
     fields once a row is expanded — hence hidden cells carry both classes —
     and any cell holding a control, like the staff Active switch, which
     would be meaningless dropped into the muted run. */
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

      /* Empty-state rows span the table — "No products yet", DataTables'
         own "No matching records found". No label belongs on those. */
      if (span > 1 || cell.classList.contains("dataTables_empty")) {
        cell.classList.add("gjc-cell-full");
        cell.removeAttribute("data-label");
        wrapValue(cell);
        column += span;
        continue;
      }

      var spec = columns[column] || { label: "", role: "" };

      /* An empty header still reserves the label column, which is what
         keeps a labelled card's values in one line. */
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

    /* A table earns the list layout by naming a title column, so placement
       can only be settled once every cell in the row has been seen. */
    for (var n = 0; n < roles.length; n++) {
      classify(roles[n].cell, roles[n].role, titled);
    }

    row.classList.toggle("gjc-row-list", titled);

    applyTarget(row, actionCell, hidden);
  }

  /* Wire the row up as a link, a button, or an expander, and say so in the
     markup so assistive tech and the stylesheet both know which it is. */
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

      /* When the row's only control is its View control, the card tap has
         replaced it — take it off the card. A row that also carries Edit,
         Void or a QR button keeps all of them. */
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

  /* One delegated listener per table, so rows replaced by a redraw need no
     rewiring. Taps that land on a control inside the row are that
     control's, not the card's. */
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

  /* Keep .gjc-cards--on in step with the viewport, on the table and on the
     .table-responsive wrapper whose overflow has to stand down with it. */
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
    /* A single-column table, or one with no header row, has nothing to
       stack — leave it alone. */
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

        /* Rows swapped inside a table we already prepared (a DataTables
           draw, the live order queue) need their labels written again. */
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
        /* Wrapping a value is itself a childList change. Drop the records
           this pass just generated so the observer doesn't re-enter on its
           own work. */
        observer.takeRecords();
      });
    });

    observer.observe(document.body, { childList: true, subtree: true });
  }

  function start() {
    scan(document);
    watch();
  }

  /* Exposed so a page that builds a table outside the observer's reach can
     ask for a pass explicitly. */
  window.GjcTableCards = { scan: scan, relabel: relabel };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start);
  } else {
    start();
  }
})();
