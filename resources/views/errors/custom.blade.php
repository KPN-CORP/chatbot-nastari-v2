<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terjadi Kesalahan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center vh-100">
    <div class="text-center">
        <h1 class="display-1 fw-bold text-danger">Oops!</h1>
        <p class="fs-3"> <span class="text-danger">Error:</span> Halaman tidak ditemukan atau akses tidak valid.</p>
        <p class="lead">
            {{ $message ?? 'Tiket yang Anda cari mungkin sudah dihapus atau link kadaluarsa.' }}
        </p>
    </div>
</body>
</html>