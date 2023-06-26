'use strict';

(function ($) {
    $(document).ready(function() {

        /**
         * Adapted from https://codemirror.net/demo/xmlcomplete.html
         */

        const textarea = 'o-bulk-mapping';

        var tags = {
            '!top': ['mapping'],
            '!attrs': {
            },
            mapping: {
                attrs: {
                },
                children: ['include', 'map', 'table'],
            },
            include: {
                attrs: {
                    mapping: null,
                }
            },
            map: {
                attrs: {
                },
                children: ['from', 'to', 'mod'],
            },
            from: {
                attrs: {
                    jsdot: null,
                    jmespath: null,
                    jsonpath: null,
                    xpath: null,
                },
            },
            to: {
                attrs: {
                    field: null,
                    datatype: null,
                    language: null,
                    visibility: null,
                },
            },
            mod: {
                attrs: {
                    raw: null,
                    val: null,
                    prepend: null,
                    pattern: null,
                    append: null,
                },
            },
            table: {
                attrs: {
                    code: null,
                    lang: null,
                    info: null,
                },
                children: ['label', 'list'],
            },
            label: {
                attrs: {
                    lang: null,
                    code: null,
                },
            },
            list: {
                attrs: {
                },
                children: ['term'],
            },
            term: {
                attrs: {
                    code: null,
                },
            },
        };

        function completeAfter(cm, pred) {
            var cur = cm.getCursor();
            if (!pred || pred()) setTimeout(function() {
                if (!cm.state.completionActive)
                    cm.showHint({completeSingle: false});
            }, 100);
            return CodeMirror.Pass;
        }

        function completeIfAfterLt(cm) {
            return completeAfter(cm, function() {
                var cur = cm.getCursor();
                return cm.getRange(CodeMirror.Pos(cur.line, cur.ch - 1), cur) == '<';
            });
        }

        function completeIfInTag(cm) {
            return completeAfter(cm, function() {
                var tok = cm.getTokenAt(cm.getCursor());
                if (tok.type == 'string' && (!/['"]/.test(tok.string.charAt(tok.string.length - 1)) || tok.string.length == 1)) return false;
                var inner = CodeMirror.innerMode(cm.getMode(), tok.state).state;
                return inner.tagName;
            });
        }

        var editor = CodeMirror.fromTextArea(document.getElementById(textarea), {
            mode: 'xml',
            lineNumbers: true,
            indentUnit: 4,
            undoDepth: 1000,
            height: 'auto',
            viewportMargin: Infinity,
            extraKeys: {
                "'<'": completeAfter,
                "'/'": completeIfAfterLt,
                "' '": completeIfInTag,
                "'='": completeIfInTag,
                'Ctrl-Space': 'autocomplete'
            },
            hintOptions: {schemaInfo: tags},
            readOnly: window.location.href.includes('/show'),
        });

    });
})(jQuery);
