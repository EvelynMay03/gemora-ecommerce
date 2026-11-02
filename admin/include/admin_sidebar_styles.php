<style>
  aside.admin-sidebar {
    min-width: 240px;
    align-self: start;
  }
  aside.admin-sidebar .admin-nav,
  aside.admin-sidebar .admin-nav-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
    list-style: none;
    margin: 0;
    padding: 0;
  }
  aside.admin-sidebar .admin-nav-link {
    display: block;
    width: 100%;
    padding: 10px 12px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 500;
    color: var(--text);
    border: 1px solid transparent;
    transition: all 0.2s ease;
    text-decoration: none;
    box-sizing: border-box;
  }
  aside.admin-sidebar .admin-nav-link:hover {
    background: var(--accent-2);
    border-color: var(--glass-border);
  }
  aside.admin-sidebar .admin-nav-link.active {
    background: var(--accent);
    color: white;
    border-color: var(--accent);
  }
  aside.admin-sidebar .admin-sidebar-actions {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--glass-border);
  }
</style>
