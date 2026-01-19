// Load icon font CSS once (for font icons)
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
    iconsPerPage: 45,
    providerType: 'font',
    svgPathsD: {},

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

    get isSvgProvider() {
      return this.providerType === 'svg';
    },

    getSvgHtml(iconName, size = 20) {
      const pathData = this.svgPathsD[iconName];
      if (!pathData) return '';

      const paths = Array.isArray(pathData) ? pathData : [pathData];
      const pathsHtml = paths.map(d => `<path d="${d}"/>`).join('');

      return `<svg style="width:${size}px;height:${size}px;" fill="currentColor" viewBox="0 0 32 32">${pathsHtml}</svg>`;
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
        this.providerType = data.provider_type || 'font';
        this.svgPathsD = data.svg_paths_d || {};
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
