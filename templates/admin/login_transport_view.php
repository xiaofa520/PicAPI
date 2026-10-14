<?php if (!isset($loginTransportPublicKey)) { http_response_code(404); exit; } ?>
<script>
(() => {
    const publicKeyPem = <?= json_encode($loginTransportPublicKey, JSON_UNESCAPED_SLASHES) ?>;
    const digestAlgo = <?= json_encode(login_transport_digest_algo(), JSON_UNESCAPED_SLASHES) ?>;
    const form = Array.from(document.forms).find(item => item.querySelector('input[name="action"][value="login"]'));
    if (!form) return;
    const currentPage = new URL(location.href);
    currentPage.hash = '';
    form.action = currentPage.href;
    const username = form.querySelector('input[name="username"]');
    const password = form.querySelector('input[name="password"]');
    const submitButton = form.querySelector('button[type="submit"]');
    if (!username || !password || !submitButton) return;
    const credentials = document.createElement('input');
    credentials.type = 'hidden';
    credentials.name = 'credentials';
    form.append(credentials);
    const toBase64 = bytes => {
        let binary = '';
        bytes.forEach(byte => { binary += String.fromCharCode(byte); });
        return btoa(binary);
    };
    const publicKeyBytes = () => {
        const base64 = publicKeyPem.replace(/-----(BEGIN|END) PUBLIC KEY-----/g, '').replace(/\s/g, '');
        const binary = atob(base64);
        return Uint8Array.from(binary, character => character.charCodeAt(0));
    };
    form.addEventListener('submit', async event => {
        if (form.dataset.encrypted === 'true') return;
        if (!window.crypto?.subtle) {
            // Leave the native form submission in place. With no explicit
            // action URL, the browser posts to this same URL and follows the
            // scheme used to open the login page (HTTP or HTTPS).
            form.dataset.encrypted = 'true';
            return;
        }
        event.preventDefault();
        submitButton.disabled = true;
        try {
            const rsaKey = await crypto.subtle.importKey('spki', publicKeyBytes(), { name: 'RSA-OAEP', hash: digestAlgo === 'sha256' ? 'SHA-256' : 'SHA-1' }, false, ['encrypt']);
            const contentKeyBytes = crypto.getRandomValues(new Uint8Array(32));
            const iv = crypto.getRandomValues(new Uint8Array(12));
            const contentKey = await crypto.subtle.importKey('raw', contentKeyBytes, 'AES-GCM', false, ['encrypt']);
            const plaintext = new TextEncoder().encode(JSON.stringify({ username: username.value, password: password.value }));
            const encryptedContent = new Uint8Array(await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, contentKey, plaintext));
            const encryptedKey = new Uint8Array(await crypto.subtle.encrypt({ name: 'RSA-OAEP' }, rsaKey, contentKeyBytes));
            const tagLength = 16;
            credentials.value = JSON.stringify({
                key: toBase64(encryptedKey),
                iv: toBase64(iv),
                ciphertext: toBase64(encryptedContent.slice(0, -tagLength)),
                tag: toBase64(encryptedContent.slice(-tagLength)),
            });
            username.disabled = true;
            password.disabled = true;
            form.dataset.encrypted = 'true';
            HTMLFormElement.prototype.submit.call(form);
        } catch (_) {
            submitButton.disabled = false;
            alert('登录凭据加密失败，请刷新页面后重试。');
        }
    });
})();
</script>
