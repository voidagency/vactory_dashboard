function usersTable() {
  return {
    users: [],
    currentPage: 1,
    showDeleteModal: false,
    totalPages: 1,
    selectedUsers: [],
    filters: {
      search: '',
      role: '',
      status: '',
    },
    sort: {
      by: 'access',
      order: 'desc',
    },
    roles: {},
    pages: [],
    loading: false,
    error: null,

    toggleAll() {
      if (this.selectedUsers.length === this.users.length) {
        this.selectedUsers = [];
      } else {
        this.selectedUsers = this.users.map(user => user.id);
      }
    },

    async deleteSelected() {
      try {
        const deletePath = drupalSettings.vactoryDashboard.deletePath;
        const response = await fetch(deletePath, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({ userIds: this.selectedUsers }),
        });

        if (response.ok) {
          if (this.currentPage > 1 && (this.users.length - this.selectedUsers.length) == 0) {
            this.currentPage -= 1;
          }
          await this.$nextTick();
          this.selectedUsers = [];
          this.showDeleteModal = false;
          await this.loadUsers(this.currentPage);
        }
      } catch (error) {
        console.error('Error deleting users:', error);
      }
    },

    async loadUsers(page = 1) {
      this.currentPage = page;
      this.loading = true;
      this.error = null;

      const params = new URLSearchParams({
        page: page,
        limit: Alpine.store('limit'),
        search: this.filters.search,
        role: this.filters.role,
        status: this.filters.status,
        sort_by: this.sort.by,
        sort_order: this.sort.order,
      });

      try {
        const dataPath = drupalSettings.vactoryDashboard.dataPath;
        const response = await fetch(`${dataPath}?${params.toString()}`);

        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }

        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
          throw new Error('Le serveur a retourné une réponse non-JSON. Veuillez réessayer.');
        }

        const data = await response.json();
        this.users = data.data;
        this.roles = data.roles;
        this.totalPages = data.pages;
        this.pages = this.generatePageNumbers(data.page, data.pages);
      } catch (error) {
        console.error('Error loading users:', error);
        this.error = error.message || 'Une erreur est survenue lors du chargement des utilisateurs.';
        this.users = [];
      } finally {
        this.loading = false;
      }
    },

    sortBy(field) {
      if (this.sort.by === field) {
        this.sort.order = this.sort.order === 'asc' ? 'desc' : 'asc';
      } else {
        this.sort.by = field;
        this.sort.order = 'desc';
      }
      this.loadUsers(this.currentPage);
    },

    generatePageNumbers(currentPage, totalPages) {
      const pages = [];
      const maxVisiblePages = 5;
      let startPage = Math.max(1, currentPage - Math.floor(maxVisiblePages / 2));
      let endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);

      if (endPage - startPage + 1 < maxVisiblePages) {
        startPage = Math.max(1, endPage - maxVisiblePages + 1);
      }

      for (let i = startPage; i <= endPage; i++) {
        pages.push(i);
      }

      return pages;
    },

    init() {
      this.loadUsers();
    },

    uncheck() {
      document.getElementById('users-check-toggle').checked = false;
    },

    resetFilters() {
      this.filters.search = '';
      this.filters.role = '';
      this.filters.status = '';
      this.loadUsers(1);
    },
  };
}

document.addEventListener('alpine:init', () => {
  Alpine.data('usersTable', usersTable);
});
