// Load icon font CSS once
(function() {
  if (!document.querySelector('link[href*="vactory_icon/style.css"]')) {
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = '/sites/default/files/vactory_icon/style.css';
    document.head.appendChild(link);
  }
})();

function iconPickerGrid(xModelPath) {
  return {
    icons: [],
    filteredIcons: [],
    isLoading: false,
    hasError: false,
    showPicker: false,
    searchQuery: '',
    selectedIcon: '',
    currentPage: 1,
    iconsPerPage: 40,

    init() {
      this.loadIcons();
      this.$nextTick(() => this.syncSelectedIcon());
    },

    syncSelectedIcon() {
      if (this.$refs.hiddenInput?.value) {
        this.selectedIcon = this.$refs.hiddenInput.value;
      }
    },

    get paginatedIcons() {
      const start = (this.currentPage - 1) * this.iconsPerPage;
      return this.filteredIcons.slice(start, start + this.iconsPerPage);
    },

    get totalPages() {
      return Math.ceil(this.filteredIcons.length / this.iconsPerPage) || 1;
    },

    async loadIcons() {
      this.isLoading = true;
      this.hasError = false;

      try {
        const response = await fetch(Drupal.url('admin/vactory-dashboard/api/icons'));
        if (!response.ok) throw new Error('Failed to load icons');
        
        const data = await response.json();
        if (data.error) throw new Error(data.error);
        
        this.icons = data.icons || [];
        this.filteredIcons = [...this.icons];
      } catch (error) {
        console.error('Error loading icons:', error);
        this.hasError = true;
        this.icons = [];
        this.filteredIcons = [];
      } finally {
        this.isLoading = false;
      }
    },

    filterIcons() {
      this.currentPage = 1;
      const query = this.searchQuery.toLowerCase().trim();
      this.filteredIcons = query
        ? this.icons.filter(icon => icon.name.toLowerCase().includes(query) || icon.label.toLowerCase().includes(query))
        : [...this.icons];
    },

    selectIcon(iconName) {
      this.selectedIcon = iconName;
      if (this.$refs.hiddenInput) {
        this.$refs.hiddenInput.value = iconName;
        this.$refs.hiddenInput.dispatchEvent(new Event('input', { bubbles: true }));
      }
      this.showPicker = false;
    },

    nextPage() {
      if (this.currentPage < this.totalPages) this.currentPage++;
    },

    prevPage() {
      if (this.currentPage > 1) this.currentPage--;
    }
  };
}
