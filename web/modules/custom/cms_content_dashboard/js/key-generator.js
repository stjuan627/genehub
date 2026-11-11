/**
 * @file
 * JavaScript behaviors for generating and copying random API Keys.
 */

(function (Drupal, once) {
  'use strict';

  /**
   * Generates a cryptographically secure random token.
   *
   * @param {string} prefix
   *   Prefix for the token.
   * @return {string}
   *   The generated token string.
   */
  function generateSecureKey(prefix = 'cd_live_') {
    const array = new Uint8Array(24);
    window.crypto.getRandomValues(array);
    const randomHex = Array.from(array, function (byte) {
      return byte.toString(16).padStart(2, '0');
    }).join('');
    return `${prefix}${randomHex}`;
  }

  Drupal.behaviors.cmsContentDashboardKeyGenerator = {
    attach: function (context) {
      once('cms-content-dashboard-key-gen', '.js-generate-api-key', context).forEach(function (button) {
        button.addEventListener('click', function (e) {
          e.preventDefault();
          const input = document.querySelector('.js-api-key-input');
          if (input) {
            const newKey = generateSecureKey();
            input.value = newKey;
            input.focus();
            input.select();

            // Provide visual feedback.
            const originalText = button.textContent;
            button.textContent = '✓ ' + Drupal.t('Generated!');
            button.classList.add('button--primary');
            setTimeout(function () {
              button.textContent = originalText;
              button.classList.remove('button--primary');
            }, 1800);
          }
        });
      });

      once('cms-content-dashboard-key-copy', '.js-copy-api-key', context).forEach(function (button) {
        button.addEventListener('click', function (e) {
          e.preventDefault();
          const input = document.querySelector('.js-api-key-input');
          if (input && input.value) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
              navigator.clipboard.writeText(input.value).then(function () {
                const originalText = button.textContent;
                button.textContent = '✓ ' + Drupal.t('Copied!');
                setTimeout(function () {
                  button.textContent = originalText;
                }, 1800);
              });
            }
            else {
              input.select();
              document.execCommand('copy');
            }
          }
        });
      });
    }
  };
})(Drupal, once);
