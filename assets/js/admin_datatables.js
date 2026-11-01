(function () {
  if (typeof window.jQuery === "undefined" || typeof jQuery.fn.DataTable === "undefined") {
    return;
  }

  jQuery(function ($) {
    $(".js-datatable").each(function () {
      const $table = $(this);
      const $body = $table.find("tbody");
      const $placeholderRows = $body.find("tr").filter(function () {
        const $cells = $(this).children("td, th");
        return (
          $cells.length === 1 &&
          parseInt($cells.eq(0).attr("colspan"), 10) > 1
        );
      });

      if ($.fn.dataTable.isDataTable(this)) {
        return;
      }

      if ($placeholderRows.length) {
        $placeholderRows.remove();
      }

      const pageLength = parseInt($table.data("page-length"), 10) || 10;
      const ordering = $table.data("ordering") !== false;
      const searching = $table.data("searching") !== false;
      const paging = $table.data("paging") !== false;
      const info = $table.data("info") !== false;
      // Hide the built-in search box when the page has its own filter input.
      const hideFilter = $table.data("hide-filter") === true;
      // Put Prev/Next above the rows, for long lists where the pager would sit far below.
      const pagerTop = $table.data("pager-top") === true;
      const pagerPart = "<'gjc-datatable-meta'p>";
      const defaultEmptyMessage =
        $table.data("empty-message") || "No records found";

      $table.DataTable({
        pageLength,
        ordering,
        searching,
        paging,
        info,
        lengthChange: false,
        autoWidth: false,
        responsive: false,
        order: [],
        language: {
          search: "",
          searchPlaceholder: "Search table",
          emptyTable: defaultEmptyMessage,
          zeroRecords: "No matching records found",
          info: "Showing _START_ to _END_ of _TOTAL_ entries",
          infoEmpty: "No entries available",
          paginate: {
            previous: "Prev",
            next: "Next",
          },
        },
        dom:
          "<'gjc-datatable-top d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3'" +
          (hideFilter ? "" : "<'gjc-datatable-meta'f>") +
          "<'gjc-datatable-meta'i>" +
          (pagerTop ? pagerPart : "") +
          ">" +
          "t" +
          "<'gjc-datatable-bottom d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mt-3'<'gjc-datatable-meta'l>" +
          (pagerTop ? "" : pagerPart) +
          ">",
      });
    });
  });
})();
