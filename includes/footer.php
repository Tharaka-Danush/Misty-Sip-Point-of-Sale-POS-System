</main>
<script>window.POS_BASE_URL = <?= json_encode(rtrim(base_url(), '/')) ?>; window.POS_CSRF = <?= json_encode(csrf_token()) ?>;</script>
<script src="<?= e(base_url('assets/js/app.js')) ?>"></script>
</body></html>

