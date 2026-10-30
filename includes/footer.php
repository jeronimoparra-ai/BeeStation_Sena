                </div> <!-- end page-content -->
        </main> <!-- end main-content -->
    </div> <!-- end app-container -->
    <?php if (!in_array(basename($_SERVER['PHP_SELF']), ['login.php', 'registro.php'], true)): ?>
    <?php endif; ?>
    <script src="js/app.js?v=<?= filemtime(__DIR__ . '/../js/app.js') ?>"></script>
</body>
</html>
