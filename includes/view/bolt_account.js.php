var insertAccountScript = function () {
    var scriptTag = document.getElementById('bolt-account');
    if (scriptTag) {
        return;
    }
    scriptTag = document.createElement('script');
    scriptTag.setAttribute('type', 'text/javascript');
    scriptTag.setAttribute('async', '');
    scriptTag.setAttribute('src', '<?= /* @noEscape */ $account_js_url; ?>');
    scriptTag.setAttribute('id', 'bolt-account');
    scriptTag.setAttribute('data-publishable-key', '<?= /* @noEscape */ $bolt_key; ?>');
    document.head.appendChild(scriptTag);
}
jQuery(document).ready(function () {
    insertAccountScript();
});