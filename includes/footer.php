            </div><!-- End content-wrapper -->
        </main>
    </div><!-- End dashboard-container -->
    
    <script>
        // Toggle sidebar on mobile
        document.getElementById('toggleSidebar').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('show');
        });
        
        document.getElementById('closeSidebar').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.remove('show');
        });
    </script>
</body>
</html>