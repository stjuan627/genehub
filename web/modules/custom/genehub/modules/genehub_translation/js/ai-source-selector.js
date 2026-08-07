(function (Drupal, once) {
  Drupal.behaviors.genehubAiSourceSelector = {
    attach(context) {
      once(
        'genehub-ai-source-selector',
        '.genehub-ai-translation-source__select',
        context,
      ).forEach((select) => {
        select.addEventListener('change', () => {
          window.location.assign(select.value);
        });
      });
    },
  };
})(Drupal, once);
