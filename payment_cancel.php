<?php
session_start();
$ref = $_GET['ref'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Cancelled</title>
    <link rel="icon" type="image/png" href="/img/logo.png">
    <link rel="shortcut icon" type="image/png" href="/img/logo.png">
    <link rel="apple-touch-icon" href="/img/logo.png">
</head>
<body>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const REF = <?php echo json_encode($ref); ?>;
Swal.fire({
    icon: 'error',
    title: 'Payment cancelled',
    text: 'You cancelled the PayPal payment. You can try again whenever you are ready.',
    confirmButtonText: 'Back to payment',
    showCancelButton: true,
    cancelButtonText: 'Go to dashboard'
}).then((result) => {
    if (result.isConfirmed && REF) {
        window.location.href = 'payment.php?ref=' + encodeURIComponent(REF);
    } else {
        window.location.href = 'dashboard.php';
    }
});
</script>
</body>
</html>