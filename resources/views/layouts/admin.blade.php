<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nastari</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />

    @stack('styles')

    <style>
        :root { --primary-color: #AB2F2B; --sidebar-width: 260px; --bg-content: #f4f6f9; }
        body { font-family: 'Poppins', sans-serif; background-color: var(--bg-content); overflow-x: hidden; }
        
        .sidebar { 
            width: var(--sidebar-width); height: 100vh; position: fixed; top: 0; left: 0; 
            background: white; z-index: 1000; transition: all 0.3s ease; border-right: 1px solid #eee; 
            overflow-y: auto; scrollbar-width: thin;
        }
        
        .sidebar-brand { padding: 30px 20px; text-align: center; border-bottom: 1px solid #f8f9fa; }
        .brand-logo-img { max-height: 60px; object-fit: contain; margin-bottom: 5px; }
        .brand-text { display: block; font-size: 1rem; font-weight: 800; color: var(--primary-color); letter-spacing: 2px; text-transform: uppercase; }

        .menu-header { font-size: 0.65rem; font-weight: 700; color: #adb5bd; text-transform: uppercase; padding-left:25px; letter-spacing: 1px; }
        .nav-link { 
            display: flex; align-items: center; padding: 10px 25px; color: #555; 
            font-weight: 500; font-size: 0.85rem; text-decoration: none; transition: 0.2s; 
        }
        .nav-link i:first-child { margin-right: 12px; font-size: 1rem; width: 20px; text-align: center; }
        .nav-link:hover { color: var(--primary-color); background: #fdf2f2; }
        .nav-link.active { color: var(--primary-color); background: #fff5f5; font-weight: 600; border-right: 4px solid var(--primary-color); }
        .nav-link .arrow { margin-left: auto; font-size: 0.7rem; transition: 0.3s; }
        .nav-link[aria-expanded="true"] .arrow { transform: rotate(180deg); }

        .submenu { list-style: none; padding: 0; background: #fcfcfc; }
        .submenu .nav-link { padding-left: 55px; font-size: 0.8rem; border-right: none; }

        .main-wrapper { margin-left: var(--sidebar-width); transition: all 0.3s ease; min-height: 100vh; display: flex; flex-direction: column; }
        body.toggled .sidebar { margin-left: calc(-1 * var(--sidebar-width)); }
        body.toggled .main-wrapper { margin-left: 0; }

        .top-navbar { background: white; height: 65px; padding: 0 30px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #eee; }
        .user-profile { display: flex; align-items: center; gap: 10px; padding-left: 15px; border-left: 1px solid #eee; }
        .user-avatar { width: 35px; height: 35px; background: var(--primary-color); color: white; border-radius: 8px; display: flex; align-items: center; justify-content: center; }
        
        .page-content { padding: 20px 30px; flex: 1; }
        .breadcrumb { font-size: 0.75rem; margin-bottom: 5px; }
        .page-title { font-size: 1.25rem; font-weight: 700; color: #333; margin: 0; }
        
        .sidebar-overlay {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.4); z-index: 999;
        }

        @media (max-width: 768px) {
            .main-wrapper { margin-left: 0 !important; }
            .sidebar { margin-left: calc(-1 * var(--sidebar-width)); }
            body.toggled .sidebar { margin-left: 0 !important; }
            body.toggled .sidebar-overlay { display: block; }
        }
    </style>
</head>
<body>

    <div class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <img src="{{ asset('storage/logo/nastari-logo.png') }}" alt="Logo" class="brand-logo-img">
            <span class="brand-text">Nastari</span>
        </div>

        <div class="sidebar-menu">
            <div class="menu-header">Main Menu</div>
            
            <a href="#dashMenu" class="nav-link collapsed {{ request()->routeIs('admin.dashboard') || request()->routeIs('hear.dashboard') ? 'active' : '' }}" data-bs-toggle="collapse">
                <i class="bi bi-speedometer2"></i><span>Dashboard</span>
                <i class="bi bi-chevron-down arrow"></i>
            </a>
            {{-- Menu "Analitik" dihapus: seluruh isinya sekarang ada di
                 dashboard Nastari sebagai bagian bernomor sesuai kebutuhan
                 monitoring, jadi tidak ada lagi dua halaman yang mengukur hal
                 yang sama dengan angka yang bisa berbeda. --}}
            <div class="collapse {{ request()->routeIs('admin.dashboard') || request()->routeIs('hear.dashboard') ? 'show' : '' }}" id="dashMenu">
                <ul class="submenu">
                    <li><a href="{{ route('admin.dashboard') }}" class="nav-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">Nastari</a></li>
                    <li><a href="{{ route('hear.dashboard') }}" class="nav-link {{ request()->routeIs('hear.dashboard') ? 'active' : '' }}">Pandu</a></li>
                </ul>
            </div>

            <a href="{{ route('admin.ruang') }}" class="nav-link {{ request()->routeIs('admin.ruang') ? 'active' : '' }}">
                <i class="bi bi-cpu-fill"></i><span>Ruang</span>
            </a>

            <a href="{{ route('admin.letter_logs.index') }}" class="nav-link {{ request()->routeIs('admin.letter_logs.*') ? 'active' : '' }}">
                <i class="bi bi-file-earmark-text-fill"></i><span>Letter History</span>
            </a>

            <a href="{{ route('admin.users') }}" class="nav-link {{ request()->routeIs('admin.users') ? 'active' : '' }}">
                <i class="bi bi-people-fill"></i><span>User Management</span>
            </a>

            <a href="#panduMenu" class="nav-link collapsed {{ request()->routeIs('hear.history') || request()->routeIs('hear.kb') ? 'active' : '' }}" data-bs-toggle="collapse">
                <i class="bi bi-headset"></i><span>Pandu Menu</span>
                <i class="bi bi-chevron-down arrow"></i>
            </a>
            <div class="collapse {{ request()->routeIs('hear.history') || request()->routeIs('hear.kb') ? 'show' : '' }}" id="panduMenu">
                <ul class="submenu">
                    <li><a href="{{ route('hear.history') }}" class="nav-link {{ request()->routeIs('hear.history') ? 'active' : '' }}">Ticket History</a></li>
                    <li><a href="{{ route('hear.kb') }}" class="nav-link {{ request()->routeIs('hear.kb') ? 'active' : '' }}">Knowledge Base</a></li>
                </ul>
            </div>

            <a href="#settingsMenu" class="nav-link collapsed {{ request()->routeIs('admin.roles.*') || request()->routeIs('admin.hco_mapping.*') ? 'active' : '' }}" data-bs-toggle="collapse">
                <i class="bi bi-sliders"></i><span>Setting</span>
                <i class="bi bi-chevron-down arrow"></i>
            </a>
            <div class="collapse {{ request()->routeIs('admin.roles.*') || request()->routeIs('admin.hco_mapping.*') ? 'show' : '' }}" id="settingsMenu">
                <ul class="submenu">
                    <li><a href="{{ route('admin.roles.create') }}" class="nav-link {{ request()->routeIs('admin.roles.create') ? 'active' : '' }}">Access Roles</a></li>
                    <li><a href="{{ route('admin.hco_mapping.index') }}" class="nav-link {{ request()->routeIs('admin.hco_mapping.*') ? 'active' : '' }}">HCO Mapping</a></li>
                </ul>
            </div>
        </div>
    </div>

    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <div class="main-wrapper">
        <nav class="top-navbar">
            <i class="bi bi-list toggle-btn fs-3" style="cursor:pointer;" onclick="toggleSidebar()"></i>
            <div class="user-profile">
                <div class="user-info text-end d-none d-sm-block">
                    <p class="user-name mb-0" style="font-size: 0.85rem; font-weight:600;">{{ Auth::user()->name ?? 'Administrator' }}</p>
                    <p class="user-id small text-muted mb-0" style="font-size: 0.7rem;">{{ Auth::user()->employee_id ?? 'System' }}</p>
                </div>
                <div class="user-avatar"><i class="bi bi-person-fill"></i></div>
            </div>
        </nav>

        <div class="page-content">
            <div class="page-header mb-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="#" class="text-decoration-none text-muted">Nastari</a></li>
                        <li class="breadcrumb-item active text-danger fw-semibold">
                            @if(request()->routeIs('admin.dashboard')) Nastari
                            @elseif(request()->routeIs('hear.dashboard')) Pandu
                            @elseif(request()->routeIs('admin.ruang')) Ruang
                            @elseif(request()->routeIs('admin.letter_logs.*')) Letter History
                            @else Control Panel @endif
                        </li>
                    </ol>
                </nav>
                <div class="page-title-row">
                    <h2 class="page-title">
                        @if(request()->routeIs('admin.dashboard')) Nastari Dashboard
                        @elseif(request()->routeIs('hear.dashboard')) Pandu Dashboard
                        @elseif(request()->routeIs('hear.history')) Ticket Records
                        @elseif(request()->routeIs('admin.hco_mapping.*')) HCO Mapping
                        @else Control Panel @endif
                    </h2>
                    @yield('header-action')
                </div>
            </div>
            @yield('content')
        </div>

        <footer class="footer">
            <div class="container-fluid d-flex justify-content-between small py-3 bg-white border-top">
                <span style="font-size: 0.75rem;">&copy; {{ date('Y') }} <strong>HCIS KPN Corp</strong></span>
                <span class="text-muted" style="font-size: 0.75rem;">v2.1.0</span>
            </div>
        </footer>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        function toggleSidebar() { $('body').toggleClass('toggled'); }
    </script>
    @stack('scripts')
</body>
</html>