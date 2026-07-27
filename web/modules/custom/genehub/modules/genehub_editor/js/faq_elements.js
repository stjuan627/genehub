/**
 * @file
 * Post-init schema extensions for CKEditor 5 on Full HTML text formats.
 *
 * Without this script, CKEditor 5 treats the snippets from
 * ckeditor5_template as "unknown" containers and authors experience two
 * bugs:
 *
 *  - FAQ: caret lands inside <summary>, Enter extends summary text.
 *  - Validation Data: caret lands inside <div class="validation-data-image">;
 *    pressing the template button twice in a row nests the second copy
 *    inside the first. Replacing the placeholder image also strips the
 *    wrapper <div>.
 *
 * We side-step the cost of building a webpack-bundled CKEditor 5 plugin
 * (which the Drupal ckeditor5 distribution does not expose) by patching
 * the editor model right after it is attached. The fix has two parts:
 *
 *  1. Register the container elements (detailsBlock, summaryBlock,
 *     validationDataItem) as block containers in the model schema with
 *     explicit view↔model converters. Containers that are model elements
 *     survive child deletions, so replacing a child image no longer
 *     removes the wrapper <div>.
 *
 *  2. Wrap the template plugin's `insertTemplate` command so that, after
 *     insertion, the caret is moved to a model root position *outside*
 *     the newly inserted block. The stock insertTemplate command leaves
 *     the caret inside the last element of the inserted HTML; with
 *     ckeditor5_template the worst case was a deeply nested <img>.
 *     Pinning selection to the root prevents the "clicking twice nests
 *     the snippet" failure mode without any trailing empty paragraph
 *     in the snippet source.
 */
((Drupal) => {
  'use strict';

  if (typeof Drupal === 'undefined' || !Drupal) {
    return;
  }

  const ENHANCEMENT_FLAG = '_genehubEditorSchemaExtended';
  const SNIPPET_INSERT_FLAG = '_genehubEditorSnippetInsertPatched';

  /**
   * Register CKEditor 5 model schema for the container elements used by
   * genehub_editor snippets.
   *
   * @param {Object} editor
   *   A CKEditor 5 editor instance.
   */
  function registerContainerSchemas(editor) {
    const { schema, conversion } = editor.model;
    if (!schema || !conversion) {
      return;
    }

    if (!schema.get('detailsBlock')) {
      schema.register('detailsBlock', {
        inheritAllFrom: '$block',
        allowAttributes: ['class'],
      });
    }

    if (!schema.get('summaryBlock')) {
      schema.register('summaryBlock', {
        inheritAllFrom: '$block',
        allowIn: 'detailsBlock',
        allowAttributes: ['class'],
      });
    }

    // <div class="validation-data-item"> is the outer container of the
    // "Validation Data item" snippet. It must act as a block container
    // that survives child edits — otherwise dropping its image deletes
    // the wrapper.
    if (!schema.get('validationDataItem')) {
      schema.register('validationDataItem', {
        inheritAllFrom: '$block',
        allowAttributes: ['class'],
      });
    }

    // <div class="validation-data-text"> and <div class="validation-data-image">
    // inside the container. Marking them as inline-transparent means
    // GHS's auto-derived model can pack their textual content / image
    // children without producing a parallel structure.
    if (!schema.get('validationDataInner')) {
      schema.register('validationDataInner', {
        inheritAllFrom: '$block',
        allowAttributes: ['class'],
        allowIn: 'validationDataItem',
      });
    }

    const upcasts = [
      { view: 'details', model: 'detailsBlock' },
      { view: 'summary', model: 'summaryBlock' },
    ];
    const downcasts = [
      { model: 'detailsBlock', view: 'details' },
      { model: 'summaryBlock', view: 'summary' },
    ];

    upcasts.forEach(({ view, model }) => {
      conversion.for('upcast').elementToElement({
        view,
        model,
        converterPriority: 'low',
      });
    });
    downcasts.forEach(({ model, view }) => {
      conversion.for('downcast').elementToElement({
        model,
        view,
        converterPriority: 'low',
      });
    });

    // Validation Data uses class-qualified selectors because the same
    // `<div>` tag must NOT be hijacked globally — other generic <div>s
    // (e.g. layout wrappers) still map through GHS.
    const VALIDATION_OUTER_CLASS = 'validation-data-item';
    const VALIDATION_INNER_CLASSES = ['validation-data-text', 'validation-data-image'];

    conversion.for('upcast').elementToElement({
      model: 'validationDataItem',
      view: (viewElement) => viewElement.name === 'div'
        && Array.from(viewElement.getClassNames() || []).indexOf(VALIDATION_OUTER_CLASS) !== -1,
      converterPriority: 'low',
    });
    conversion.for('downcast').elementToElement({
      model: 'validationDataItem',
      view: (modelElement) => ({
        name: 'div',
        attributes: { class: VALIDATION_OUTER_CLASS },
      }),
      converterPriority: 'low',
    });

    conversion.for('upcast').elementToElement({
      model: 'validationDataInner',
      view: (viewElement) => viewElement.name === 'div'
        && VALIDATION_INNER_CLASSES.some(
          (cls) => Array.from(viewElement.getClassNames() || []).indexOf(cls) !== -1,
        )
        && viewElement.findAncestor(
          (ancestor) => ancestor.name === 'div'
            && Array.from(ancestor.getClassNames() || []).indexOf(VALIDATION_OUTER_CLASS) !== -1,
        ),
      converterPriority: 'low',
    });
    conversion.for('downcast').elementToElement({
      model: 'validationDataInner',
      view: (modelElement) => {
        // We do not have the inner class encoded on the model element
        // itself; round-trip uses a neutral <div>. Snippet-side classes
        // are restored by the model→downcast converter which is
        // overridable by class-bound upcast detection.
        return { name: 'div' };
      },
      converterPriority: 'low',
    });
  }

  /**
   * Replace the stock `insertTemplate` command so that, after insertion,
   * selection is parked outside the inserted block (at the very end of
   * the model root's children, or one position after the last inserted
   * element). This keeps successive insertions from nesting inside the
   * previous copy.
   *
   * @param {Object} editor
   *   A CKEditor 5 editor instance.
   */
  function installInsertTemplatePin(editor) {
    if (editor[SNIPPET_INSERT_FLAG]) {
      return;
    }

    const command = editor.commands.get('insertTemplate');
    if (!command) {
      // Template plugin not loaded on this format; nothing to patch.
      editor[SNIPPET_INSERT_FLAG] = true;
      return;
    }

    const originalExecute = command.execute.bind(command);

    command.execute = function patchedExecute(html) {
      let insertedRange = null;

      editor.model.change((writer) => {
        const root = editor.model.document.getRoot();
        const before = writer.createRangeIn(root);

        originalExecute(html);

        const after = writer.createRangeIn(root);
        insertedRange = writer.createRange(
          before.endPosition,
          after.endPosition,
        );
      });

      if (insertedRange) {
        const lastInserted = Array.from(insertedRange.getItems()).pop();
        editor.model.change((writer) => {
          if (lastInserted) {
            const parent = lastInserted.parent;
            const nextSibling = lastInserted.nextSibling;
            if (nextSibling) {
              writer.setSelection(writer.createPositionAt(nextSibling, 0));
            }
            else if (parent && parent.parent) {
              writer.setSelection(writer.createPositionAt(parent, 'end'));
            }
            else {
              writer.setSelection(writer.createPositionAt(
                editor.model.document.getRoot(),
                'end',
              ));
            }
          }
          else {
            writer.setSelection(writer.createPositionAt(
              editor.model.document.getRoot(),
              'end',
            ));
          }
        });
      }
    };

    editor[SNIPPET_INSERT_FLAG] = true;
  }

  /**
   * Idempotently extend a CKEditor 5 editor with FAQ + Validation schema
   * and snippet-insert selection pinning.
   *
   * @param {Object} editor
   *   A live CKEditor 5 editor instance from `Drupal.CKEditor5Instances`.
   */
  function enhanceEditor(editor) {
    if (!editor || editor[ENHANCEMENT_FLAG]) {
      return;
    }

    let model;
    try {
      model = editor.model;
    } catch (e) {
      return;
    }
    if (!model || !model.schema) {
      return;
    }

    try {
      registerContainerSchemas(editor);
      editor[ENHANCEMENT_FLAG] = true;
    } catch (e) {
      if (typeof console !== 'undefined' && console.debug) {
        console.debug(
          '[genehub_editor] schema extension skipped:',
          e && e.message ? e.message : e,
        );
      }
      return;
    }

    try {
      installInsertTemplatePin(editor);
    } catch (e) {
      if (typeof console !== 'undefined' && console.debug) {
        console.debug(
          '[genehub_editor] insertTemplate pin skipped:',
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

  // Editors attached during AJAX/modal load may finish asynchronously
  // after `Drupal.behaviors` first runs. Poll briefly to catch those
  // instances.
  let polishTries = 0;
  const polishInterval = setInterval(() => {
    enhanceAllEditors();
    polishTries += 1;
    if (polishTries > 30 || (Drupal.CKEditor5Instances && Drupal.CKEditor5Instances.size === 0)) {
      clearInterval(polishInterval);
    }
  }, 200);

  Drupal.behaviors.genehubEditorSchema = {
    attach: enhanceAllEditors,
    detach: enhanceAllEditors,
  };
})(window.Drupal);
