/* Retry of the offline page (external so a Content-Security-Policy without inline scripts still allows it). */
document.getElementById('retry').addEventListener('click', function () {
    window.location.reload();
});

window.addEventListener('online', function () {
    window.location.reload();
});
