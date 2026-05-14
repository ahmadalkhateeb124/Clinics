</main>

<?php if (empty($ntFormMode)): ?>
<footer class="admin-footer">
    <small>© <?= date('Y') ?> <?= APP_NAME ?> — Admin Panel</small>
</footer>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BP_ASSETS ?>js/admin.js"></script>
</body>
</html>
