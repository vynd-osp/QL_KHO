// assets/js/script.js - JS chung
function openModal(id) {
  const modal = document.getElementById(id);
  if (modal) modal.style.display = 'flex';
}

function closeModal(id) {
  const modal = document.getElementById(id);
  if (modal) modal.style.display = 'none';
}

// ================================
// Mobile Menu Toggle
// ================================
function toggleSidebar() {
  console.log('=== toggleSidebar called ===');
  console.log('Window width:', window.innerWidth);
  
  const sidebar = document.querySelector('.sidebar');
  
  if (!sidebar) {
    console.error('❌ Sidebar not found!');
    return;
  }
  
  console.log('✅ Sidebar found');
  
  // Chỉ hoạt động trên mobile/tablet (<= 768px)
  if (window.innerWidth > 768) {
    console.log('⚠️ Desktop mode - sidebar should always be visible');
    return;
  }
  
  let overlay = document.getElementById('sidebar-overlay');
  
  // Tạo overlay nếu chưa có
  if (!overlay) {
    console.log('Creating overlay...');
    overlay = document.createElement('div');
    overlay.id = 'sidebar-overlay';
    overlay.onclick = function(e) {
      e.stopPropagation();
      console.log('Overlay clicked');
      toggleSidebar();
    };
    document.body.appendChild(overlay);
    console.log('✅ Overlay created');
  }
  
  // Toggle sidebar
  const isOpen = sidebar.classList.contains('open');
  console.log('Current sidebar state:', isOpen ? 'OPEN' : 'CLOSED');
  
  if (isOpen) {
    // Đóng sidebar
    console.log('Closing sidebar...');
    sidebar.classList.remove('open');
    if (overlay) overlay.classList.remove('show');
    // Khôi phục body overflow
    document.body.style.overflow = '';
    console.log('✅ Sidebar closed');
  } else {
    // Mở sidebar
    console.log('Opening sidebar...');
    sidebar.classList.add('open');
    if (overlay) overlay.classList.add('show');
    // Chỉ set overflow hidden trên mobile
    document.body.style.overflow = 'hidden';
    console.log('✅ Sidebar opened');
  }
  
  console.log('=== toggleSidebar finished ===');
}

// Đóng sidebar khi click vào nav link trên mobile
document.addEventListener('DOMContentLoaded', function() {
  const navLinks = document.querySelectorAll('.nav-link');
  navLinks.forEach(link => {
    link.addEventListener('click', function() {
      if (window.innerWidth <= 768) {
        toggleSidebar();
      }
    });
  });
  
  // Đóng sidebar khi resize về desktop
  let resizeTimeout;
  window.addEventListener('resize', function() {
    // Debounce để tránh gọi quá nhiều lần
    clearTimeout(resizeTimeout);
    resizeTimeout = setTimeout(function() {
      const sidebar = document.querySelector('.sidebar');
      const overlay = document.getElementById('sidebar-overlay');
      
      if (window.innerWidth > 768) {
        // Trên desktop: đảm bảo sidebar luôn hiển thị
        if (sidebar) {
          sidebar.classList.remove('open');
          // Chỉ set transform nếu đang ở mobile mode
          if (sidebar.style.transform && sidebar.style.transform.includes('-100%')) {
            sidebar.style.transform = 'translateX(0)';
          }
        }
        if (overlay) {
          overlay.classList.remove('show');
        }
        // Khôi phục body overflow
        if (document.body.style.overflow === 'hidden') {
          document.body.style.overflow = '';
        }
      } else {
        // Trên mobile: đảm bảo sidebar ẩn nếu chưa mở
        if (sidebar && !sidebar.classList.contains('open')) {
          sidebar.style.transform = 'translateX(-100%)';
        }
      }
    }, 100);
  });
  
  // Khởi tạo trạng thái sidebar khi load trang
  const initSidebar = document.querySelector('.sidebar');
  if (initSidebar) {
    if (window.innerWidth > 768) {
      // Desktop: sidebar luôn hiển thị, không cần set transform
      initSidebar.classList.remove('open');
      // Xóa inline style nếu có
      if (initSidebar.style.transform) {
        initSidebar.style.transform = '';
      }
    } else {
      // Mobile: sidebar ẩn mặc định
      initSidebar.style.transform = 'translateX(-100%)';
      initSidebar.classList.remove('open');
    }
  }
  
  console.log('✅ Mobile menu initialized');
});


// Close modal khi click ngoài
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}

// Close modal khi nhấn phím ESC
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        const openModal = document.querySelector('.modal[style*="flex"]');
        if (openModal) {
            openModal.style.display = 'none';
        }
    }
});

// ================================
// Table Filters & Sorting (Google Sheets–like)
// ================================
(function () {
  function isNumeric(value) {
    if (value === null || value === undefined) return false;
    const normalized = String(value).replace(/\./g, '').replace(/,/g, '').replace(/\s/g, '').replace(/VNĐ|VND/gi, '');
    return normalized.trim() !== '' && !isNaN(Number(normalized));
  }

  function parseValue(value, isNumericColumn = false) {
    if (!value || value === null || value === undefined) {
      return isNumericColumn ? 0 : ''; // Coi như 0 cho cột số, rỗng cho cột text
    }
    
    const strValue = String(value).trim();
    
    // Nếu là giá trị rỗng, "-", "Chưa có sản phẩm", hoặc các giá trị đặc biệt
    if (strValue === '' || strValue === '-' || strValue.toLowerCase().includes('chưa có') || strValue.toLowerCase().includes('không có')) {
      return isNumericColumn ? 0 : strValue.toLowerCase(); // Coi như 0 cho cột số
    }
    
    if (isNumeric(strValue)) {
      const normalized = strValue.replace(/\./g, '').replace(/,/g, '').replace(/\s/g, '').replace(/VNĐ|VND/gi, '');
      return Number(normalized);
    }
    
    // Nếu không phải số và không phải chuỗi đặc biệt, thử parse lại
    // Loại bỏ các ký tự không phải số
    const cleaned = strValue.replace(/[^\d.,]/g, '').replace(/\./g, '').replace(/,/g, '');
    if (cleaned && !isNaN(Number(cleaned))) {
      return Number(cleaned);
    }
    
    // Nếu vẫn không phải số
    if (isNumericColumn) {
      return 0; // Coi như 0 cho cột số
    }
    return strValue.toLowerCase(); // Giữ nguyên cho cột text
  }

  function closeAllMenus(except) {
    document.querySelectorAll('.th-filter-menu').forEach(function (menu) {
      if (menu !== except) menu.classList.remove('open');
    });
  }

  function setupFilterButton(th) {
    const btn = th.querySelector('.th-filter-btn');
    if (!btn) return;

    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      const menu = th.querySelector('.th-filter-menu');
      if (!menu) return;

      const wasOpen = menu.classList.contains('open');
      closeAllMenus(menu);
      if (!wasOpen) {
        menu.classList.add('open');
        positionMenu(menu, th);
        populateFilterValues(th, menu);
      }
    });
  }

  function positionMenu(menu, th) {
    const rect = th.getBoundingClientRect();
    menu.style.left = rect.left + 'px';
    menu.style.top = (rect.bottom + 5) + 'px';
  }

  function populateFilterValues(th, menu) {
    const column = th.getAttribute('data-column');
    if (!column) return;

    const table = th.closest('table');
    if (!table) return;

    const tbody = table.querySelector('tbody');
    if (!tbody) return;

    // Lưu trạng thái checked hiện tại (nếu có)
    const existingCheckboxes = menu.querySelectorAll('.th-value-item input[type="checkbox"]');
    const checkedStates = {};
    existingCheckboxes.forEach(cb => {
      checkedStates[cb.value.trim()] = cb.checked;
    });

    // Lấy tất cả rows (kể cả những rows đang bị ẩn) để có đầy đủ giá trị
    const allRows = Array.from(tbody.querySelectorAll('tr'));
    const values = new Set();
    
    allRows.forEach(row => {
      const cell = row.querySelector(`td[data-column="${column}"]`);
      if (cell) {
        const text = cell.textContent.trim();
        if (text) values.add(text);
      }
    });

    const valuesBlock = menu.querySelector('.th-values-block');
    if (!valuesBlock) return;

    const list = valuesBlock.querySelector('.th-values-list');
    if (!list) return;

    list.innerHTML = '';
    
    if (values.size === 0) {
      list.innerHTML = '<div class="th-empty">Không có dữ liệu</div>';
      return;
    }

    const sortedValues = Array.from(values).sort((a, b) => {
      const aParsed = parseValue(a);
      const bParsed = parseValue(b);
      if (typeof aParsed === 'number' && typeof bParsed === 'number') {
        return aParsed - bParsed;
      }
      return String(aParsed).localeCompare(String(bParsed));
    });

    sortedValues.forEach(value => {
      const item = document.createElement('div');
      item.className = 'th-value-item';
      
      const checkbox = document.createElement('input');
      checkbox.type = 'checkbox';
      checkbox.className = 'th-value-checkbox';
      // Khôi phục trạng thái checked nếu có, mặc định là true
      checkbox.checked = checkedStates.hasOwnProperty(value) ? checkedStates[value] : true;
      checkbox.value = value;
      checkbox.setAttribute('data-value', value);
      checkbox.addEventListener('change', function() {
        updateSelectAllCheckbox(menu);
        applyFilter();
      });
      
      const label = document.createElement('label');
      label.textContent = value;
      label.style.cursor = 'pointer';
      label.style.marginLeft = '8px';
      label.onclick = function(e) {
        e.stopPropagation();
        checkbox.checked = !checkbox.checked;
        checkbox.dispatchEvent(new Event('change'));
      };
      
      item.appendChild(checkbox);
      item.appendChild(label);
      list.appendChild(item);
    });

    // Setup checkbox "Chọn tất cả"
    setupSelectAllCheckbox(menu);
    
    // Setup lại event listeners cho các nút trong menu này
    setupMenuButtons(menu, th);
  }

  function setupSelectAllCheckbox(menu) {
    const valuesActions = menu.querySelector('.th-values-actions');
    if (!valuesActions) return;
    
    // Xóa checkbox "Chọn tất cả" cũ nếu có
    const existingSelectAll = valuesActions.querySelector('.th-select-all-item');
    if (existingSelectAll) {
      existingSelectAll.remove();
    }
    
    // Tạo checkbox "Chọn tất cả"
    const selectAllItem = document.createElement('div');
    selectAllItem.className = 'th-value-item th-select-all-item';
    selectAllItem.style.borderBottom = '1px solid #e5e7eb';
    selectAllItem.style.paddingBottom = '6px';
    selectAllItem.style.marginBottom = '6px';
    
    const selectAllCheckbox = document.createElement('input');
    selectAllCheckbox.type = 'checkbox';
    selectAllCheckbox.className = 'th-select-all-checkbox';
    selectAllCheckbox.id = 'select-all-' + Math.random().toString(36).substr(2, 9);
    
    // Kiểm tra xem tất cả checkbox có được chọn không
    const allCheckboxes = menu.querySelectorAll('.th-value-checkbox');
    const allChecked = allCheckboxes.length > 0 && Array.from(allCheckboxes).every(cb => cb.checked);
    selectAllCheckbox.checked = allChecked;
    
    selectAllCheckbox.addEventListener('change', function() {
      const checkboxes = menu.querySelectorAll('.th-value-checkbox');
      checkboxes.forEach(cb => {
        cb.checked = selectAllCheckbox.checked;
      });
      applyFilter();
    });
    
    const selectAllLabel = document.createElement('label');
    selectAllLabel.setAttribute('for', selectAllCheckbox.id);
    selectAllLabel.textContent = 'Chọn tất cả';
    selectAllLabel.style.cursor = 'pointer';
    selectAllLabel.style.marginLeft = '8px';
    selectAllLabel.style.fontWeight = '600';
    
    selectAllItem.appendChild(selectAllCheckbox);
    selectAllItem.appendChild(selectAllLabel);
    
    // Chèn vào đầu danh sách
    const list = menu.querySelector('.th-values-list');
    if (list && list.firstChild) {
      list.insertBefore(selectAllItem, list.firstChild);
    } else if (list) {
      list.appendChild(selectAllItem);
    }
  }

  function updateSelectAllCheckbox(menu) {
    const selectAllCheckbox = menu.querySelector('.th-select-all-checkbox');
    if (!selectAllCheckbox) return;
    
    const allCheckboxes = menu.querySelectorAll('.th-value-checkbox');
    const allChecked = allCheckboxes.length > 0 && Array.from(allCheckboxes).every(cb => cb.checked);
    selectAllCheckbox.checked = allChecked;
  }

  function setupMenuButtons(menu, th) {
    // Xóa event listeners cũ nếu có
    const sortAscBtn = menu.querySelector('button[data-sort="asc"]');
    const sortDescBtn = menu.querySelector('button[data-sort="desc"]');
    const filterBtn = menu.querySelector('button[data-action="filter"]');
    const clearBtn = menu.querySelector('button[data-action="clear"]');
    
    // Clone và replace để xóa event listeners cũ
    if (sortAscBtn) {
      const newBtn = sortAscBtn.cloneNode(true);
      sortAscBtn.parentNode.replaceChild(newBtn, sortAscBtn);
      newBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        const column = th.getAttribute('data-column');
        if (column) sortTable(column, 'asc');
      });
    }
    
    if (sortDescBtn) {
      const newBtn = sortDescBtn.cloneNode(true);
      sortDescBtn.parentNode.replaceChild(newBtn, sortDescBtn);
      newBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        const column = th.getAttribute('data-column');
        if (column) sortTable(column, 'desc');
      });
    }
    
    if (filterBtn) {
      const newBtn = filterBtn.cloneNode(true);
      filterBtn.parentNode.replaceChild(newBtn, filterBtn);
      newBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        applyFilter();
        closeAllMenus();
      });
    }
    
    if (clearBtn) {
      const newBtn = clearBtn.cloneNode(true);
      clearBtn.parentNode.replaceChild(newBtn, clearBtn);
      newBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        const column = th.getAttribute('data-column');
        if (column) {
          clearFilter(column);
          closeAllMenus();
        }
      });
    }
  }

  function sortTable(column, direction) {
    const tables = document.querySelectorAll('.management-table');
    
    tables.forEach(table => {
      const tbody = table.querySelector('tbody');
      if (!tbody) return;

      const th = table.querySelector(`th[data-column="${column}"]`);
      if (!th) return;

      const rows = Array.from(tbody.querySelectorAll('tr'));
      
      // Kiểm tra xem table có dùng rowspan không
  const hasRowspan = rows.some(row => {
    const cell = row.querySelector(`td[data-column="${column}"]`);
    return cell && cell.hasAttribute('rowspan');
  });

      
      if (hasRowspan) {
        // Xử lý table có rowspan: nhóm các rows lại theo data-id
        const rowGroups = new Map();
        let currentGroup = null;
        let currentGroupRows = [];
        
        rows.forEach(row => {
          const rowId = row.getAttribute('data-id');
          if (rowId) {
            // Lưu group cũ nếu có
            if (currentGroup) {
              rowGroups.set(currentGroup, currentGroupRows);
            }
            // Bắt đầu group mới
            currentGroup = rowId;
            currentGroupRows = [row];
          } else if (currentGroup) {
            // Row thuộc group hiện tại (không có data-id, là row con)
            currentGroupRows.push(row);
          } else {
            // Row đơn lẻ không có group
            rowGroups.set(row, [row]);
          }
        });
        
        // Lưu group cuối cùng
        if (currentGroup) {
          rowGroups.set(currentGroup, currentGroupRows);
        }
        
        // Xác định các columns cần rowspan và thứ tự của chúng (dựa vào row đầu tiên có rowspan)
        // Lưu thông tin này TRƯỚC KHI sort để dùng cho cả sort và rebuild
        const columnsWithRowspan = [];
        const columnOrder = [];
        if (rows.length > 0) {
          const firstRowWithRowspan = rows.find(row => {
            const cells = row.querySelectorAll('td[rowspan]');
            return cells.length > 0;
          });
          if (firstRowWithRowspan) {
            // Lấy tất cả cells trong row đầu tiên để biết thứ tự
            const allCells = firstRowWithRowspan.querySelectorAll('td');
            allCells.forEach((cell, cellIndex) => {
              const col = cell.getAttribute('data-column');
              if (col) {
                columnOrder.push(col);
                if (cell.hasAttribute('rowspan')) {
                  columnsWithRowspan.push(col);
                }
              }
            });
          }
        }
        
        // Lưu thông tin về rowspan của mỗi group TRƯỚC KHI sort
        const groupRowspanInfo = new Map();
        rowGroups.forEach((groupRows, groupId) => {
          const firstRow = groupRows[0];
          const hasRowspan = firstRow.querySelectorAll('td[rowspan]').length > 0;
          groupRowspanInfo.set(groupId, hasRowspan);
        });
        
        // Kiểm tra xem cột này có phải là cột số không (dựa vào nhiều giá trị)
        let isNumericColumn = false;
        let numericCount = 0;
        let totalChecked = 0;
        
        // Kiểm tra 10 giá trị đầu tiên để xác định
        for (let i = 0; i < Math.min(10, rows.length); i++) {
          const cell = rows[i].querySelector(`td[data-column="${column}"]`);
          if (cell) {
            totalChecked++;
            const testValue = cell.textContent.trim();
            if (isNumeric(testValue) || testValue === '' || testValue === '-' || 
                testValue.toLowerCase().includes('chưa có') || 
                testValue.toLowerCase().includes('không có') ||
                testValue.toLowerCase().includes('vnđ') ||
                testValue.toLowerCase().includes('vnd') ||
                testValue.toLowerCase().includes('cái')) {
              numericCount++;
            }
          }
        }
        
        // Nếu hơn 50% giá trị là số hoặc giá trị đặc biệt → coi là cột số
        isNumericColumn = totalChecked > 0 && (numericCount / totalChecked) >= 0.5;
        
        // Sort các groups
        // Luôn lấy giá trị từ row đầu tiên (sản phẩm đầu tiên) của mỗi group để sort
        const sortedGroups = Array.from(rowGroups.entries()).sort(([aId, aRows], [bId, bRows]) => {
          // Luôn lấy từ row đầu tiên của group (sản phẩm đầu tiên trong phiếu)
          const aFirstRow = aRows[0];
          const bFirstRow = bRows[0];
          
          const aCell = aFirstRow.querySelector(`td[data-column="${column}"]`);
          const bCell = bFirstRow.querySelector(`td[data-column="${column}"]`);
          
          if (!aCell || !bCell) {
            // Nếu không tìm thấy cell, coi như 0 (cho cột số) hoặc rỗng (cho cột text) và so sánh
            const aValue = aCell ? parseValue(aCell.textContent.trim(), isNumericColumn) : (isNumericColumn ? 0 : '');
            const bValue = bCell ? parseValue(bCell.textContent.trim(), isNumericColumn) : (isNumericColumn ? 0 : '');
            
            if (isNumericColumn) {
              return direction === 'asc' ? (aValue - bValue) : (bValue - aValue);
            } else {
              return direction === 'asc' ? String(aValue).localeCompare(String(bValue)) : String(bValue).localeCompare(String(aValue));
            }
          }
          
          const aValue = parseValue(aCell.textContent.trim(), isNumericColumn);
          const bValue = parseValue(bCell.textContent.trim(), isNumericColumn);
          
          let comparison = 0;
          if (isNumericColumn || (typeof aValue === 'number' && typeof bValue === 'number')) {
            // Xử lý như số
            comparison = aValue - bValue;
          } else {
            // Xử lý như chuỗi
            comparison = String(aValue).localeCompare(String(bValue));
          }
          
          return direction === 'asc' ? comparison : -comparison;
        });
        
        // Xóa tất cả rows
        tbody.innerHTML = '';
        
        // Thêm lại các groups đã sort và rebuild rowspan
        sortedGroups.forEach(([groupId, groupRows]) => {
          // Lấy thông tin rowspan đã lưu trước đó
          const firstRowHasRowspan = groupRowspanInfo.get(groupId) || false;
          
          groupRows.forEach((row, rowIndex) => {
            // Chỉ rebuild rowspan nếu:
            // 1. Là row đầu tiên
            // 2. Group có nhiều hơn 1 row
            // 3. Row đầu tiên thực sự có rowspan (phiếu có sản phẩm)
            if (rowIndex === 0 && groupRows.length > 1 && firstRowHasRowspan) {
              const rowspanValue = groupRows.length;
              // Tìm và set rowspan cho các cells đúng vị trí
              const cells = row.querySelectorAll('td');
              columnOrder.forEach(col => {
                if (columnsWithRowspan.includes(col)) {
                  // Tìm cell có data-column tương ứng
                  const cell = Array.from(cells).find(c => c.getAttribute('data-column') === col);
                  if (cell) {
                    cell.setAttribute('rowspan', rowspanValue);
                  }
                }
              });
            } else if (rowIndex > 0 && firstRowHasRowspan) {
              // Đảm bảo các rows con không có cells ở vị trí rowspan
              // Chỉ xử lý nếu row đầu tiên có rowspan
              const cells = row.querySelectorAll('td');
              cells.forEach(cell => {
                const col = cell.getAttribute('data-column');
                if (col && columnsWithRowspan.includes(col)) {
                  // Nếu row con có cell ở vị trí rowspan, xóa nó
                  cell.remove();
                }
              });
            }
            
            tbody.appendChild(row);
          });
        });
      } else {
        // Xử lý table thông thường (không có rowspan)
        // Kiểm tra xem cột này có phải là cột số không (dựa vào nhiều giá trị)
        let isNumericColumn = false;
        let numericCount = 0;
        let totalChecked = 0;
        
        // Kiểm tra 10 giá trị đầu tiên để xác định
        for (let i = 0; i < Math.min(10, rows.length); i++) {
          const cell = rows[i].querySelector(`td[data-column="${column}"]`);
          if (cell) {
            totalChecked++;
            const testValue = cell.textContent.trim();
            if (isNumeric(testValue) || testValue === '' || testValue === '-' || 
                testValue.toLowerCase().includes('chưa có') || 
                testValue.toLowerCase().includes('không có') ||
                testValue.toLowerCase().includes('vnđ') ||
                testValue.toLowerCase().includes('vnd') ||
                testValue.toLowerCase().includes('cái')) {
              numericCount++;
            }
          }
        }
        
        // Nếu hơn 50% giá trị là số hoặc giá trị đặc biệt → coi là cột số
        isNumericColumn = totalChecked > 0 && (numericCount / totalChecked) >= 0.5;
        
        rows.sort((a, b) => {
          const aCell = a.querySelector(`td[data-column="${column}"]`);
          const bCell = b.querySelector(`td[data-column="${column}"]`);
          
          // Nếu không có cell, coi như 0 (cho cột số) hoặc rỗng (cho cột text)
          const aValue = aCell ? parseValue(aCell.textContent.trim(), isNumericColumn) : (isNumericColumn ? 0 : '');
          const bValue = bCell ? parseValue(bCell.textContent.trim(), isNumericColumn) : (isNumericColumn ? 0 : '');
          
          let comparison = 0;
          if (isNumericColumn || (typeof aValue === 'number' && typeof bValue === 'number')) {
            // Xử lý như số
            comparison = aValue - bValue;
          } else {
            // Xử lý như chuỗi
            comparison = String(aValue).localeCompare(String(bValue));
          }
          
          return direction === 'asc' ? comparison : -comparison;
        });
        
        rows.forEach(row => tbody.appendChild(row));
      }
    });

    closeAllMenus();
  }

  function applyFilter() {
    const tables = document.querySelectorAll('.management-table');
    
    tables.forEach(table => {
      const thead = table.querySelector('thead');
      if (!thead) return;

      const ths = thead.querySelectorAll('th[data-column]');
      const filters = {};
      
      ths.forEach(th => {
        const column = th.getAttribute('data-column');
        if (!column || column === 'actions') return;

        const menu = th.querySelector('.th-filter-menu');
        if (!menu) return;

        const allCheckboxes = menu.querySelectorAll('.th-value-checkbox');
        const checkedCheckboxes = menu.querySelectorAll('.th-value-checkbox:checked');
        
        // Chỉ áp dụng filter nếu có ít nhất một checkbox bị uncheck
        if (allCheckboxes.length > 0 && checkedCheckboxes.length < allCheckboxes.length) {
          filters[column] = Array.from(checkedCheckboxes).map(cb => cb.value.trim());
        }
      });

      const tbody = table.querySelector('tbody');
      if (!tbody) return;

      // Lấy tất cả rows (kể cả những rows đang bị ẩn)
      const rows = Array.from(tbody.querySelectorAll('tr'));
      
      // Kiểm tra xem table có sử dụng rowspan không (QL nhập/xuất)
      const hasRowspan = rows.some(row => {
        const cells = row.querySelectorAll('td[data-column]');
        return Array.from(cells).some(cell => cell.hasAttribute('rowspan'));
      });
      
      if (hasRowspan) {
        // Xử lý table có rowspan: nhóm các rows lại theo data-id
        // Lưu trữ thông tin rowspan ban đầu trước khi filter
        const originalRowspanInfo = new Map();
        rows.forEach((row, rowIndex) => {
          const rowId = row.getAttribute('data-id');
          if (rowId && !originalRowspanInfo.has(rowId)) {
            // Đếm số rows trong group này
            let groupSize = 1;
            let nextRow = row.nextElementSibling;
            while (nextRow && !nextRow.getAttribute('data-id')) {
              groupSize++;
              nextRow = nextRow.nextElementSibling;
            }
            originalRowspanInfo.set(rowId, groupSize);
          }
        });
        
        const rowGroups = new Map();
        let currentGroup = null;
        let currentGroupRows = [];
        
        rows.forEach(row => {
          const rowId = row.getAttribute('data-id');
          if (rowId) {
            // Lưu group cũ nếu có
            if (currentGroup) {
              rowGroups.set(currentGroup, currentGroupRows);
            }
            // Bắt đầu group mới
            currentGroup = rowId;
            currentGroupRows = [row];
          } else if (currentGroup) {
            // Row thuộc group hiện tại (không có data-id, là row con)
            currentGroupRows.push(row);
          } else {
            // Row đơn lẻ không có group
            rowGroups.set(row, [row]);
          }
        });
        
        // Lưu group cuối cùng
        if (currentGroup) {
          rowGroups.set(currentGroup, currentGroupRows);
        }
        
        // Xác định các columns cần rowspan và thứ tự của chúng (dựa vào row đầu tiên có rowspan)
        const columnsWithRowspan = [];
        const columnOrder = [];
        if (rows.length > 0) {
          const firstRowWithRowspan = rows.find(row => {
            const cells = row.querySelectorAll('td[rowspan]');
            return cells.length > 0;
          });
          if (firstRowWithRowspan) {
            // Lấy tất cả cells trong row đầu tiên để biết thứ tự
            const allCells = firstRowWithRowspan.querySelectorAll('td');
            allCells.forEach((cell, cellIndex) => {
              const col = cell.getAttribute('data-column');
              if (col) {
                columnOrder.push(col);
                if (cell.hasAttribute('rowspan')) {
                  columnsWithRowspan.push(col);
                }
              }
            });
          }
        }
        
        // Lưu thông tin về rowspan của mỗi group cho filter
        const groupRowspanInfoForFilter = new Map();
        rowGroups.forEach((groupRows, groupId) => {
          const firstRow = groupRows[0];
          const hasRowspan = firstRow.querySelectorAll('td[rowspan]').length > 0;
          groupRowspanInfoForFilter.set(groupId, hasRowspan);
        });
        
        // Hàm helper để rebuild rowspan cho một group
        const rebuildRowspanForGroup = (groupRows, groupId) => {
          if (groupRows.length <= 1) {
            // Nếu chỉ có 1 row, xóa rowspan nếu có
            const firstRow = groupRows[0];
            if (firstRow) {
              const cellsWithRowspan = firstRow.querySelectorAll('td[rowspan]');
              cellsWithRowspan.forEach(cell => {
                cell.removeAttribute('rowspan');
              });
            }
            return;
          }
          
          // Lấy thông tin rowspan đã lưu
          const firstRowHasRowspan = groupRowspanInfoForFilter.get(groupId) || false;
          
          // Chỉ rebuild nếu row đầu tiên thực sự có rowspan
          if (!firstRowHasRowspan) return;
          
          const firstRow = groupRows[0];
          
          // Sử dụng số rows hiển thị thực tế (không phải tổng số rows)
          const rowspanValue = groupRows.length;
          
          // Tìm và set rowspan cho các cells đúng vị trí trong row đầu tiên
          const cells = firstRow.querySelectorAll('td');
          columnOrder.forEach(col => {
            if (columnsWithRowspan.includes(col)) {
              const cell = Array.from(cells).find(c => c.getAttribute('data-column') === col);
              if (cell) {
                cell.setAttribute('rowspan', rowspanValue);
              }
            }
          });
          
          // Đảm bảo các rows con không có cells ở vị trí rowspan
          for (let i = 1; i < groupRows.length; i++) {
            const row = groupRows[i];
            const cells = row.querySelectorAll('td');
            cells.forEach(cell => {
              const col = cell.getAttribute('data-column');
              if (col && columnsWithRowspan.includes(col)) {
                // Nếu row con có cell ở vị trí rowspan, xóa nó
                cell.remove();
              }
            });
          }
        };
        
        // Áp dụng filter cho từng group
        rowGroups.forEach((groupRows, groupId) => {
          const firstRow = groupRows[0];
          let shouldShow = true;
          
          // Nếu không có filter nào, hiển thị tất cả
          if (Object.keys(filters).length === 0) {
            groupRows.forEach(row => {
              row.style.display = '';
            });
            rebuildRowspanForGroup(groupRows, groupId);
            return;
          }
          
          // Kiểm tra từng cột có filter
          Object.keys(filters).forEach(column => {
            // Tìm cell trong group (có thể ở row đầu hoặc bất kỳ row nào)
            let cell = null;
            for (const row of groupRows) {
              cell = row.querySelector(`td[data-column="${column}"]`);
              if (cell) break;
            }
            
            if (!cell) {
              shouldShow = false;
              return;
            }
            
            const cellValue = cell.textContent.trim();
            // So sánh chính xác giá trị
            if (!filters[column].includes(cellValue)) {
              shouldShow = false;
            }
          });
          
          // Ẩn/hiện cả group
          groupRows.forEach(row => {
            row.style.display = shouldShow ? '' : 'none';
          });
        });
        
        // Sau khi filter, rebuild rowspan cho tất cả các groups
        // Cần đếm lại số rows hiển thị trong mỗi group (chỉ tính các rows có display !== 'none')
        rowGroups.forEach((groupRows, groupId) => {
          // Lọc ra các rows đang hiển thị
          const visibleRows = groupRows.filter(row => {
            const display = window.getComputedStyle(row).display;
            return display !== 'none';
          });
          
          // Chỉ rebuild nếu có rows hiển thị
          if (visibleRows.length > 0) {
            rebuildRowspanForGroup(visibleRows, groupId);
          }
        });
      } else {
        // Xử lý table thông thường (không có rowspan)
        rows.forEach(row => {
          let shouldShow = true;
          
          // Nếu không có filter nào, hiển thị tất cả
          if (Object.keys(filters).length === 0) {
            row.style.display = '';
            return;
          }
          
          // Kiểm tra từng cột có filter
          Object.keys(filters).forEach(column => {
            const cell = row.querySelector(`td[data-column="${column}"]`);
            if (!cell) {
              shouldShow = false;
              return;
            }
            
            const cellValue = cell.textContent.trim();
            // So sánh chính xác giá trị
            if (!filters[column].includes(cellValue)) {
              shouldShow = false;
            }
          });
          
          row.style.display = shouldShow ? '' : 'none';
        });
      }
    });
  }

  function clearFilter(column) {
    const tables = document.querySelectorAll('.management-table');
    
    tables.forEach(table => {
      const th = table.querySelector(`th[data-column="${column}"]`);
      if (!th) return;

      const menu = th.querySelector('.th-filter-menu');
      if (!menu) return;

      const checkboxes = menu.querySelectorAll('.th-value-checkbox');
      checkboxes.forEach(cb => {
        cb.checked = true;
      });
      
      // Cập nhật checkbox "Chọn tất cả"
      updateSelectAllCheckbox(menu);
      
      // Áp dụng filter để hiển thị lại tất cả
      applyFilter();
    });
  }

  // Setup sort buttons ban đầu (sẽ được setup lại mỗi khi menu mở)
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('thead th[data-column]').forEach(th => {
      const menu = th.querySelector('.th-filter-menu');
      if (menu) {
        setupMenuButtons(menu, th);
      }
    });
  });

  document.addEventListener('click', function (e) {
    if (!e.target.closest('.th-filter-wrapper')) {
      closeAllMenus();
    }
  });

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('thead th[data-column]').forEach(setupFilterButton);
  });
})();
