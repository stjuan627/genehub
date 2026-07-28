/**
 * @file
 * Preserves real text when pasting content copied from WPS Office.
 *
 * WPS can apply `mso-spacerun: yes` to spans containing real text. Core's
 * PasteFromOffice normalizer assumes those spans contain only whitespace and
 * replaces their complete contents with non-breaking spaces.
 */
((Drupal, CKEditor5) => {
  'use strict';

  if (!Drupal || !CKEditor5 || !CKEditor5.pasteFromOffice) {
    return;
  }

  const PATCH_FLAG = '_genehubEditorWpsPastePatched';
  const SPACERUN_MARKER = /mso-spacerun\s*:/i;
  const SPACERUN_STYLE = /(?:^|;)\s*mso-spacerun\s*:/i;

  /**
   * Remove the spacerun declaration from spans that contain real text.
   *
   * @param {string} html
   *   Clipboard HTML.
   *
   * @return {string|null}
   *   Sanitized HTML, or null when no WPS-style anomaly was found.
   */
  function preserveSpacerunText(html) {
    if (!html || !SPACERUN_MARKER.test(html)) {
      return null;
    }

    const document = new DOMParser().parseFromString(html, 'text/html');
    let changed = false;

    document.querySelectorAll('span[style]').forEach((element) => {
      const style = element.getAttribute('style') || '';
      if (!SPACERUN_STYLE.test(style)) {
        return;
      }

      if (!(element.textContent || '').trim()) {
        return;
      }

      const sanitizedStyle = style
        .replace(/(^|;)\s*mso-spacerun\s*:[^;]*(?=;|$)/gi, '$1')
        .replace(/^\s*;\s*/, '')
        .trim();

      if (sanitizedStyle) {
        element.setAttribute('style', sanitizedStyle);
      }
      else {
        element.removeAttribute('style');
      }
      changed = true;
    });

    return changed ? document.documentElement.outerHTML : null;
  }

  /**
   * Install the preprocessing listener on one CKEditor instance.
   *
   * @param {Object} editor
   *   A live CKEditor 5 editor instance.
   */
  function patchEditor(editor) {
    if (!editor || editor[PATCH_FLAG]) {
      return;
    }

    const clipboard = editor.plugins.get('ClipboardPipeline');
    const parseHtml = CKEditor5.pasteFromOffice.parsePasteOfficeHtml
      || CKEditor5.pasteFromOffice.parseHtml;
    if (!clipboard || typeof parseHtml !== 'function') {
      return;
    }

    clipboard.on('inputTransformation', (event, data) => {
      const html = data.dataTransfer.getData('text/html');
      const sanitizedHtml = preserveSpacerunText(html);
      if (!sanitizedHtml) {
        return;
      }

      data._parsedData = parseHtml(
        sanitizedHtml,
        editor.editing.view.document.stylesProcessor,
      );
    }, { priority: 'highest' });

    editor[PATCH_FLAG] = true;
  }

  /**
   * Patch current and asynchronously attached editor instances.
   */
  function patchAllEditors() {
    if (!Drupal.CKEditor5Instances) {
      return;
    }
    Drupal.CKEditor5Instances.forEach((editor) => patchEditor(editor));
  }

  let patchTries = 0;
  const patchInterval = setInterval(() => {
    patchAllEditors();
    patchTries += 1;
    if (patchTries > 30) {
      clearInterval(patchInterval);
    }
  }, 200);

  Drupal.behaviors.genehubEditorWpsPasteFix = {
    attach: patchAllEditors,
  };
})(window.Drupal, window.CKEditor5);
