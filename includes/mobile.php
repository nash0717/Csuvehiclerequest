<?php /* mobile.php – include in every page's <head> */ ?>
<style>
.hamburger-btn {
    display: none;
    background: none;
    border: none;
    cursor: pointer;
    padding: 4px;
    color: #800000;
    font-size: 1.2rem;
    line-height: 1;
    margin-right: .5rem;
}
.sidebar-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.45);
    z-index: 199;
}
.sidebar-overlay.open { display: block; }

@media (max-width: 768px) {
    .hamburger-btn { display: flex; align-items: center; }
    .sidebar {
        transform: translateX(-100%);
        transition: transform 0.25s ease;
        z-index: 200;
        position: fixed !important;
        top: 0;
        left: 0;
        height: 100vh;
        overflow-y: auto;
    }
    .sidebar.open { transform: translateX(0) !important; }
    .topbar { margin-left: 0 !important; }
    .main-content { margin-left: 0 !important; padding: 1rem; }
    .row.g-3 > .col-6.col-lg { flex: 0 0 50%; max-width: 50%; }
    .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .welcome-banner { flex-direction: column; align-items: flex-start; gap: 1rem; }
    .banner-clock-block { align-items: flex-start; }
    .page-grid { grid-template-columns: 1fr !important; }
    .avail-grid { grid-template-columns: 1fr !important; }
    .stats-row { grid-template-columns: 1fr 1fr !important; }
    .modal-dialog { margin: auto 0 0; max-width: 100%; }
    .modal-content { border-radius: 16px 16px 0 0 !important; }
    .filter-tabs { gap: 4px; }
    .filter-tab { font-size: .68rem; padding: 3px 10px; }
    .filter-btn { font-size: .74rem; padding: 3px 10px; }
    .section-header { flex-wrap: wrap; gap: .5rem; }
    .topbar-title { font-size: .82rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .topbar-title i { display: none; }
    .topbar-user > div > div:last-child { display: none; }
    .search-box { min-width: 120px; }
    .search-card { padding: .85rem; }
    .search-card .col-md-3,
    .search-card .col-md-2 { flex: 0 0 50%; max-width: 50%; }
    .search-card .col-md-2:last-child { flex: 0 0 100%; max-width: 100%; }
    .range-display { display: flex; flex-wrap: wrap; width: 100%; font-size: .76rem; }
    .section-header .ms-auto { font-size: .7rem !important; white-space: nowrap; }
    .avail-card { padding: .7rem .85rem; }
    .spill { font-size: .7rem; padding: 3px 9px; }
    .table th:nth-child(3),
    .table td:nth-child(3),
    .table th:nth-child(6),
    .table td:nth-child(6) { display: none; }
}
</style>