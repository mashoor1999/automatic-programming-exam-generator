    </div> <!-- /app-body -->

    <footer class="app-footer">
        <div class="container footer-inner">
            <p class="footer-copy">
                &copy; <?= date('Y') ?> Automatic Exam Generator. All rights reserved.
            </p>

            <p class="footer-dev">
                Developed by <span>Khaled</span>
            </p>
        </div>
    </footer>
</div> <!-- /app-shell -->

<script>
    (function () {
        const body = document.body;
        const themeToggle = document.getElementById('themeToggle');
        const savedTheme = localStorage.getItem('ag_theme');

        if (savedTheme === 'dark') {
            body.classList.add('dark-mode');
            if (themeToggle) {
                themeToggle.textContent = '☀️';
            }
        }

        if (themeToggle) {
            themeToggle.addEventListener('click', function () {
                body.classList.toggle('dark-mode');

                if (body.classList.contains('dark-mode')) {
                    localStorage.setItem('ag_theme', 'dark');
                    themeToggle.textContent = '☀️';
                } else {
                    localStorage.setItem('ag_theme', 'light');
                    themeToggle.textContent = '🌙';
                }
            });
        }
    })();
</script>

</body>
</html>