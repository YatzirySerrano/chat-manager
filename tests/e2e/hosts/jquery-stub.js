/*
 * Minimal stand-in for jQuery in the widget E2E (tests/e2e/widget.mjs): the
 * scenario answers https://code.jquery.com/* with this file, so the adapter
 * ($.fn.chatManager) is exercised without adding jQuery as a dependency.
 * Only what the adapter uses: $(selector), .each() and $.fn.
 */
(function () {
    function Collection(elements) {
        this.elements = elements;
        this.length = elements.length;
    }

    function $(selector) {
        return new Collection(
            Array.prototype.slice.call(document.querySelectorAll(selector)),
        );
    }

    $.fn = Collection.prototype;
    Collection.prototype.each = function (callback) {
        this.elements.forEach(function (element, index) {
            callback.call(element, index, element);
        });

        return this;
    };
    window.jQuery = window.$ = $;
})();
