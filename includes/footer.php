        </main>
    </div>
</div>
<script>
(function () {
    var body = document.body;
    var btn = document.getElementById('menuBtn');
    var overlay = document.getElementById('overlay');
    if (btn) btn.addEventListener('click', function () { body.classList.toggle('nav-open'); });
    if (overlay) overlay.addEventListener('click', function () { body.classList.remove('nav-open'); });
})();
</script>
</body>
</html>