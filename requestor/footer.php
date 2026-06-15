</div><!-- end page-content -->
</div><!-- end main-wrap -->

<script>
// Close sidebar on outside click (mobile)
document.addEventListener('click', function(e) {
    const sidebar = document.getElementById('sidebar');
    const hamburger = document.querySelector('.hamburger');
    if (sidebar && sidebar.classList.contains('open')) {
        if (!sidebar.contains(e.target) && e.target !== hamburger && !hamburger.contains(e.target)) {
            sidebar.classList.remove('open');
        }
    }
});
</script>
</body>
</html>