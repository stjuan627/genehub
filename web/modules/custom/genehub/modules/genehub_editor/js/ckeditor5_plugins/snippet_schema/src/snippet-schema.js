import { Plugin } from 'ckeditor5/src/core';
import { toWidget, toWidgetEditable, Widget } from 'ckeditor5/src/widget';

const OUTER_MODELS = new Set(['faqItem', 'validationDataItem']);

export default class SnippetSchema extends Plugin {
  static get pluginName() {
    return 'SnippetSchema';
  }

  static get requires() {
    return [Widget];
  }

  init() {
    this._defineSchema();
    this._defineConverters();
    this._moveSelectionAfterInsertedSnippet();
  }

  _defineSchema() {
    const { schema } = this.editor.model;

    schema.register('faqItem', {
      inheritAllFrom: '$blockObject',
    });
    schema.register('faqQuestion', {
      isLimit: true,
      allowIn: 'faqItem',
      allowContentOf: '$block',
    });
    schema.register('faqContent', {
      isLimit: true,
      allowIn: 'faqItem',
      allowContentOf: '$root',
      allowChildren: '$text',
    });
    schema.register('faqAnswer', {
      isLimit: true,
      allowIn: 'faqItem',
      allowContentOf: '$block',
    });

    schema.register('validationDataItem', {
      inheritAllFrom: '$blockObject',
    });
    schema.register('validationDataText', {
      isLimit: true,
      allowIn: 'validationDataItem',
      allowContentOf: '$root',
    });
    schema.register('validationDataImage', {
      isLimit: true,
      allowIn: 'validationDataItem',
      allowContentOf: '$root',
      allowChildren: 'htmlImg',
    });
  }

  _defineConverters() {
    this._defineOuterConverter(
      'faqItem',
      'details',
      'faq-item',
      'FAQ item',
    );
    this._defineEditableConverter(
      'faqQuestion',
      'summary',
      'faq-question',
    );
    this._defineEditableConverter('faqContent', 'div', 'faq-content');
    this._defineEditableConverter('faqAnswer', 'p', 'faq-answer');

    this._defineOuterConverter(
      'validationDataItem',
      'div',
      'validation-data-item',
      'Validation data item',
    );
    this._defineEditableConverter(
      'validationDataText',
      'div',
      'validation-data-text',
    );
    this._defineEditableConverter(
      'validationDataImage',
      'div',
      'validation-data-image',
    );
  }

  _defineOuterConverter(model, name, className, label) {
    const { conversion } = this.editor;
    const view = { name, classes: className };

    conversion.for('upcast').elementToElement({
      view,
      model,
      converterPriority: 'high',
    });
    conversion.for('dataDowncast').elementToElement({
      model,
      view,
    });
    conversion.for('editingDowncast').elementToElement({
      model,
      view: (modelElement, { writer }) => toWidget(
        writer.createContainerElement(name, { class: className }),
        writer,
        { label },
      ),
    });
  }

  _defineEditableConverter(model, name, className) {
    const { conversion } = this.editor;
    const view = { name, classes: className };

    conversion.for('upcast').elementToElement({
      view,
      model,
      converterPriority: 'high',
    });
    conversion.for('dataDowncast').elementToElement({
      model,
      view,
    });
    conversion.for('editingDowncast').elementToElement({
      model,
      view: (modelElement, { writer }) => toWidgetEditable(
        writer.createEditableElement(name, { class: className }),
        writer,
      ),
    });
  }

  _moveSelectionAfterInsertedSnippet() {
    const { model } = this.editor;

    this.listenTo(model.document, 'change:data', () => {
      const selected = model.document.selection.getSelectedElement();
      if (!selected || !OUTER_MODELS.has(selected.name)) {
        return;
      }

      model.change((writer) => {
        writer.setSelection(selected, 'after');
      });
    }, { priority: 'low' });
  }
}
