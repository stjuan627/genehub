/**
 * @file
 * Post-init FAQ schema extension for CKEditor 5 on Full HTML text formats.
 *
 * Registers <details> and <summary> as proper block elements on the editor
 * model so the FAQ accordion snippet can be edited naturally. Without this,
 * CKEditor 5 treats the inserted <details> as an "unknown" wrapper and:
 *  - Caret lands inside <summary> after the snippet is inserted, and
 *    pressing Enter extends the summary text instead of breaking out.
 *  - In-place editing cannot create sibling paragraphs after <details>.
 *
 * We intentionally do NOT extend the editor's `extraPlugins` because that
 * would require shipping a webpack-bundled CKEditor 5 plugin (which the
 * Drupal ckeditor5 distribution does not expose without a build pipeline).
 * Instead we patch the editor's schema right after it is attached, via the
 * `Drupal.behaviors` lifecycle. Schema registrations are persistent across
 * the editor's lifetime, so subsequent template insertions and editing all
 * benefit from them.
 */
((Drupal) => {
  'use strict';

  if (typeof Drupal === 'undefined' || !Drupal) {
    return;
  }

  const ENHANCEMENT_FLAG = '_genehubEditorFaqSchemaApplied';

  /**
   * Idempotently extend a CKEditor 5 editor with FAQ-friendly schema.
   *
   * @param {Object} editor
   *   A live CKEditor 5 editor instance from `Drupal.CKEditor5Instances`.
   */
  function enhanceEditor(editor) {
    if (!editor || editor[ENHANCEMENT_FLAG]) {
      return;
    }

    let model;
    let conversion;
    try {
      model = editor.model;
      conversion = editor.conversion;
    } catch (e) {
      return;
    }
    if (!model || !model.schema || !conversion) {
      return;
    }

    try {
      // <details> as a block container allowing <summary> as the lead child
      // and any other block (paragraph, etc.) following. We model it as a
      // new element name to keep it distinct from GHS's auto-derived model.
      if (!model.schema.get('detailsBlock')) {
        model.schema.register('detailsBlock', {
          inheritAllFrom: '$block',
          allowAttributes: ['class'],
        });
      }

      // <summary> as a block element that lives inside <details> and may
      // contain inline text only (no nested blocks), like a heading.
      if (!model.schema.get('summaryBlock')) {
        model.schema.register('summaryBlock', {
          inheritAllFrom: '$block',
          allowIn: 'detailsBlock',
          allowAttributes: ['class'],
        });
      }

      // View → model (upcast). Used when HTML is loaded into the editor
      // and when the template plugin inserts HTML fragments.
      conversion.for('upcast').elementToElement({
        view: 'details',
        model: 'detailsBlock',
        converterPriority: 'low',
      });
      conversion.for('upcast').elementToElement({
        view: 'summary',
        model: 'summaryBlock',
        converterPriority: 'low',
      });

      // Model → view (downcast). Used when rendering model back to HTML.
      conversion.for('downcast').elementToElement({
        model: 'detailsBlock',
        view: 'details',
        converterPriority: 'low',
      });
      conversion.for('downcast').elementToElement({
        model: 'summaryBlock',
        view: 'summary',
        converterPriority: 'low',
      });

      editor[ENHANCEMENT_FLAG] = true;
    } catch (e) {
      // Schema registrations are sensitive; if a conflicting registration
      // already exists, swallow the error to avoid breaking the editor.
      if (typeof console !== 'undefined' && console.debug) {
        console.debug(
          '[genehub_editor] FAQ schema extension skipped:',
          e && e.message ? e.message : e,
        );
      }
    }
  }

  /**
   * Enhance every editor currently known to Drupal, plus any future ones
   * attached via AJAX or modals.
   */
  function enhanceAllEditors() {
    if (!Drupal.CKEditor5Instances) {
      return;
    }
    Drupal.CKEditor5Instances.forEach((editor) => enhanceEditor(editor));
  }

  // Edits on a page render may be a single editor that finishes
  // asynchronously after `Drupal.behaviors` first runs. Poll briefly to
  // catch those instances.
  let polishTries = 0;
  const polishInterval = setInterval(() => {
    enhanceAllEditors();
    polishTries += 1;
    if (polishTries > 30 || (Drupal.CKEditor5Instances && Drupal.CKEditor5Instances.size === 0)) {
      // Stop polling after ~6 s or once Drupal is confident no editor exists.
      // Editors added later (e.g. via AJAX) re-trigger Drupal.behaviors,
      // which will run `enhanceAllEditors` again.
      clearInterval(polishInterval);
    }
  }, 200);

  Drupal.behaviors.genehubEditorFaqSchema = {
    attach: enhanceAllEditors,
    detach: enhanceAllEditors,
  };
})(window.Drupal);
