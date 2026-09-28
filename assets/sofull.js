(function(){
  function openSOP(){
    window.open('/docs/officepack_view.php?f=INDEX_v3.1.html','_blank');
  }

  // Shortcut: F1 opens SOP
  document.addEventListener('keydown', function(e){
    if (e.key === 'F1'){
      e.preventDefault();
      openSOP();
    }
  });

  // Global search → quick navigation
  const input = document.getElementById('global-search');
  const routes = [
    {k:['dashboard','home','utama'], href:'/'},
    {k:['product','md','item','barang'], href:'/products'},
    {k:['purchasing','pr','po'], href:'/purchasing'},
    {k:['receiving','gr','penerimaan'], href:'/receiving'},
    {k:['sales','crm','o2c','do','delivery order','order'], href:'/crm/do/new'},
    {k:['lead','leads','rfq','inquiry','prospect'], href:'/crm/leads'},
    {k:['inventory','stok','lot','expiry','exp'], href:'/inventory'},
    {k:['stockcount','opname','cycle count','count'], href:'/stockcount'},
    {k:['transfer','scm','mutasi'], href:'/transfer'},
    {k:['shipping','ship','pengiriman','delivery','scm shipping'], href:'/shipping'},
    {k:['ar','receivable','piutang','customer payment','pembayaran customer','payment'], href:'/ar'},
    {k:['ap','invoice','hutang'], href:'/ap'},
    {k:['gl','report','act'], href:'/gl'},
    {k:['ops','outbox','audit','notif'], href:'/ops'},
    {k:['admin','user management','users','akun'], href:'/admin/users'},
    {k:['approval','doa','matrix','limit'], href:'/admin/approval'},
    {k:['docs','help'], href:'/docs'},
    {k:['sop','manual'], href:'/docs/officepack_view.php?f=INDEX_v3.1.html', external:true},
    {k:['website','public','company profile','profil perusahaan'], href:'/website/'},
    {k:['store','ecommerce','shop'], href:'/store'},
  ];

  if (input){
    input.addEventListener('keydown', function(e){
      if (e.key !== 'Enter') return;
      const q = (input.value || '').trim().toLowerCase();
      if (!q) return;

      const hit = routes.find(r => r.k.some(kk => q.includes(kk)));
      if (hit){
        if (hit.external) window.open(hit.href,'_blank');
        else window.location.href = hit.href;
      }
    });
  }

  // ====== CRM Sales DO form helpers ======
  function fmtIDR(n){
    try {
      return new Intl.NumberFormat('id-ID').format(Math.round(n || 0));
    } catch(_e){
      return String(Math.round(n || 0));
    }
  }

  function num(v){
    const n = parseFloat(v);
    return isNaN(n) ? 0 : n;
  }

  function initSalesDO(){
    const form = document.getElementById('do-form');
    if (!form) return;

    const officeSel = document.getElementById('office_site_id');
    const taxSel = document.getElementById('tax_profile_id');
    const customerSel = document.getElementById('customer_id');
    const customerNewBox = document.getElementById('customer_new_box');

    const sumSubtotal = document.getElementById('sum-subtotal');
    const sumTax = document.getElementById('sum-tax');
    const sumGrand = document.getElementById('sum-grand');

    const priceMap = window.__RMI_PRICE_MAP || {};

    function getOfficeCode(){
      if (!officeSel) return null;
      const opt = officeSel.options[officeSel.selectedIndex];
      return opt ? (opt.getAttribute('data-code') || null) : null;
    }

    function taxRate(){
      if (!taxSel) return 0;
      const opt = taxSel.options[taxSel.selectedIndex];
      return opt ? num(opt.getAttribute('data-rate') || 0) : 0;
    }

    function rows(){
      return Array.from(document.querySelectorAll('#do-items-body tr'));
    }

    function setPriceAndUomForRow(tr){
      const prodSel = tr.querySelector('.item-barcode');
      const uomInp = tr.querySelector('.item-uom');
      const priceInp = tr.querySelector('.item-price');

      if (!prodSel) return;
      const bc = (prodSel.value || '').trim();
      const opt = prodSel.options[prodSel.selectedIndex];
      const uom = opt ? (opt.getAttribute('data-uom') || '') : '';

      if (uomInp) uomInp.value = uom || '';

      const officeCode = getOfficeCode();
      const masterPrice = (officeCode && priceMap[officeCode] && priceMap[officeCode][bc]) ? priceMap[officeCode][bc] : null;

      // If master price exists, auto-fill unless user already typed something
      if (priceInp){
        const current = num(priceInp.value);
        if (masterPrice !== null){
          if (current <= 0) priceInp.value = masterPrice;
        }
      }
    }

    function recalc(){
      let subtotal = 0;

      rows().forEach(tr => {
        const qtyInp = tr.querySelector('.item-qty');
        const priceInp = tr.querySelector('.item-price');
        const subInp = tr.querySelector('.item-subtotal');

        const qty = qtyInp ? num(qtyInp.value) : 0;
        const price = priceInp ? num(priceInp.value) : 0;
        const line = qty * price;
        subtotal += line;

        if (subInp) subInp.value = fmtIDR(line);
      });

      const rate = taxRate();
      const tax = subtotal * rate / 100;
      const grand = subtotal + tax;

      if (sumSubtotal) sumSubtotal.textContent = fmtIDR(subtotal);
      if (sumTax) sumTax.textContent = fmtIDR(tax);
      if (sumGrand) sumGrand.textContent = fmtIDR(grand);
    }

    function bindRow(tr){
      const prodSel = tr.querySelector('.item-barcode');
      const qtyInp = tr.querySelector('.item-qty');
      const priceInp = tr.querySelector('.item-price');

      if (prodSel){
        prodSel.addEventListener('change', function(){
          setPriceAndUomForRow(tr);
          recalc();
        });
      }
      if (qtyInp) qtyInp.addEventListener('input', recalc);
      if (priceInp) priceInp.addEventListener('input', recalc);
    }

    // Customer new box toggle
    function toggleCustomerBox(){
      if (!customerSel || !customerNewBox) return;
      const isNew = (customerSel.value || '0') === '0';
      customerNewBox.style.display = isNew ? 'block' : 'none';
    }
    if (customerSel){
      customerSel.addEventListener('change', toggleCustomerBox);
      toggleCustomerBox();
    }

    // Office / tax change affects price + totals
    if (officeSel){
      officeSel.addEventListener('change', function(){
        rows().forEach(setPriceAndUomForRow);
        recalc();
      });
    }
    if (taxSel){
      taxSel.addEventListener('change', recalc);
    }

    // Bind initial rows
    rows().forEach(tr => {
      bindRow(tr);
      setPriceAndUomForRow(tr);
    });
    recalc();

    // Add new line
    const addBtn = document.getElementById('add-line');
    if (addBtn){
      addBtn.addEventListener('click', function(){
        const tbody = document.getElementById('do-items-body');
        if (!tbody) return;
        const tpl = tbody.querySelector('tr');
        if (!tpl) return;
        const clone = tpl.cloneNode(true);

        // reset inputs
        clone.querySelectorAll('input').forEach(inp => {
          if (inp.classList.contains('item-subtotal')) inp.value = '0';
          else inp.value = '';
        });
        clone.querySelectorAll('select').forEach(sel => {
          sel.value = '';
        });

        tbody.appendChild(clone);
        bindRow(clone);
        setPriceAndUomForRow(clone);
        recalc();
      });
    }
  }

  // init on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSalesDO);
  } else {
    initSalesDO();
  }
})();
