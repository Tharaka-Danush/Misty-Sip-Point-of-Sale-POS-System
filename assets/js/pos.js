(() => {
  const USER_STORAGE_SCOPE=String(window.POS_USER_ID||'guest');
  const HIGH_DISCOUNT_THRESHOLD=Math.max(0,Math.min(100,Number(window.POS_HIGH_DISCOUNT_THRESHOLD??10)));
  const CART_STORAGE_KEY=`mistySipCurrentBillV2:${USER_STORAGE_SCOPE}`;
  const HELD_STORAGE_KEY=`mistySipHeldOrdersV2:${USER_STORAGE_SCOPE}`,LAST_SALE_KEY=`mistySipLastSaleId:${USER_STORAGE_SCOPE}`;
  const cart=new Map(),cards=[...document.querySelectorAll('.product-card')],cartEl=document.getElementById('cartItems');
  const modal=document.getElementById('sizeModal'),modalTitle=document.getElementById('sizeModalTitle'),sizeOptions=document.getElementById('sizeOptions'),sizeGroup=sizeOptions.closest('.customize-group'),bobaOptions=document.getElementById('bobaOptions'),sugarOptions=document.getElementById('sugarOptions'),iceOptions=document.getElementById('iceOptions'),toppingOptions=document.getElementById('toppingOptions'),customPrice=document.getElementById('customPrice'),addButton=document.getElementById('addCustomizedDrink');
  const bobaGroup=bobaOptions.closest('.customize-group'),bobaStep=bobaGroup.querySelector('.group-title>span'),primaryColumn=bobaGroup.closest('.customize-column'),sugarGroup=sugarOptions.closest('.customize-group'),sugarStep=sugarGroup.querySelector('.group-title>span'),iceGroup=iceOptions.closest('.customize-group'),iceStep=iceGroup.querySelector('.group-title>span'),toppingGroup=document.getElementById('toppingGroup'),optionsColumn=sugarGroup.closest('.customize-column'),modalBox=modal.querySelector('.size-modal-box'),helpText=modal.querySelector('.customize-head>p:last-child');
  const customizeHead=modal.querySelector('.customize-head');
  customizeHead.insertAdjacentHTML('afterbegin','<div class="popup-brand-logo"><img src="assets/logo.png" alt="Misty Sip"></div><div class="swing-cup" aria-hidden="true"><span class="cup-straw"></span><span class="cup-lid"></span><span class="cup-body"><i></i><i></i><i></i><b>MS</b></span></div>');
  const bobaChoices=[{value:'No Boba',label:'No Boba',charge:0},{value:'Regular Boba',label:'Regular',charge:100},{value:'Extra Boba',label:'Extra',charge:150}],toppingChoices=[{value:'No Topping',label:'No Topping',charge:0},{value:'Honey',label:'Honey',charge:50},{value:'Chocolate',label:'Chocolate',charge:75},{value:'Pomegranate',label:'Pomegranate',charge:100}],sugarChoices=['0%','20%','50%','75%','Normal'],iceChoices=['Less Ice','Normal Ice'];
  const fixedSizeCategories={'Fruit Tea':'L','Ice Cream':'M','Hot Drinks':'M','Sundae':'M','Fruit Salad':'M'};
  const noDisplaySizeCategories=['Ice Cream','Hot Drinks','Fruit Salad'];
  let selectedProduct=null,selectedVariant=null,selectedBoba=bobaChoices[0],selectedTopping=toppingChoices[0],selectedSugar='Normal',selectedIce='Normal Ice',loyaltyFreeKey=null;
  const money=n=>Number(n).toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2});
  const escapeHtml=v=>{const d=document.createElement('div');d.textContent=v;return d.innerHTML;};
  const billOptions=x=>{const details=[];if(x.boba_level!=='No Boba')details.push('Boba +');if(x.topping_level&&x.topping_level!=='No Topping')details.push(`Topping: ${x.topping_level}`);if(x.category==='Hot Drinks')details.push(x.sugar_level==='Normal'?'Normal Sugar':'Less Sugar');else if(!['Ice Cream','Sundae','Fruit Salad'].includes(x.category)){details.push(x.sugar_level==='Normal'?'Normal Sugar':'Less Sugar');details.push(x.ice_level==='Less Ice'?'Less Ice':'Normal Ice');}return details.join(' • ');};
  function saveCart(){try{localStorage.setItem(CART_STORAGE_KEY,JSON.stringify([...cart.values()]));}catch(e){}}
  function restoreCart(){try{const saved=JSON.parse(localStorage.getItem(CART_STORAGE_KEY)||'[]');if(Array.isArray(saved))saved.forEach(x=>{if(x&&x.key)cart.set(x.key,x);});}catch(e){localStorage.removeItem(CART_STORAGE_KEY);}}
  const getTotals=()=>{const items=[...cart.values()],subtotal=items.reduce((s,x)=>s+x.price*x.qty,0),manualDiscount=Math.min(Math.max(Number(document.getElementById('discount').value)||0,0),subtotal),freeItem=loyaltyFreeKey?cart.get(loyaltyFreeKey):null,loyaltyDiscount=freeItem?freeItem.price:0,discount=Math.min(manualDiscount+loyaltyDiscount,subtotal),total=subtotal-discount,paid=Number(document.getElementById('paidAmount').value)||0;return{subtotal,manualDiscount,loyaltyDiscount,discount,total,paid,balance:Math.max(paid-total,0)};};
  function render(){
    if(!cart.size)cartEl.innerHTML='<div class="empty"><span class="empty-cart-icon" aria-hidden="true"><svg viewBox="0 0 64 64"><path d="M7 11h8l6 27h28l6-20H19"/><circle cx="27" cy="49" r="3"/><circle cx="47" cy="49" r="3"/><path d="M25 27h19"/></svg></span><span>Select a product to start.</span></div>';
    else cartEl.innerHTML='<div class="bill-grid-head"><span>Item</span><span>Size</span><span>Qty</span><span>Price</span><span>Total</span><span></span></div>'+[...cart.values()].map(x=>{const options=billOptions(x),size=noDisplaySizeCategories.includes(x.category)?'—':(fixedSizeCategories[x.category]||x.size||'—'),free=x.key===loyaltyFreeKey;return `<div class="cart-row compact-bill-row ${free?'loyalty-free-row':''}"><div class="bill-item-name"><strong>${escapeHtml(x.name)}</strong>${free?'<small class="free-label">LOYALTY FREE DRINK</small>':(options?`<small>${escapeHtml(options)}</small>`:'')}</div><span class="bill-size">${escapeHtml(size)}</span><div class="qty"><button data-act="minus" data-key="${escapeHtml(x.key)}">−</button><input class="qty-direct" data-key="${escapeHtml(x.key)}" inputmode="numeric" value="${x.qty}" aria-label="Quantity"><button data-act="plus" data-key="${escapeHtml(x.key)}">+</button></div><b class="bill-unit-price">${money(x.price)}</b><b class="bill-line-total">${money(x.price*x.qty)}</b><button class="bill-remove" data-act="remove" data-key="${escapeHtml(x.key)}" type="button" aria-label="Remove ${escapeHtml(x.name)}">×</button></div>`;}).join('');
    saveCart();
    if(loyaltyFreeKey&&!cart.has(loyaltyFreeKey))loyaltyFreeKey=null;const t=getTotals();document.getElementById('subtotal').textContent=money(t.subtotal);document.getElementById('total').textContent=money(t.total);document.getElementById('balance').textContent=money(t.balance);const loyaltyButton=document.getElementById('loyaltyFreeBtn');loyaltyButton.classList.toggle('active',Boolean(loyaltyFreeKey));loyaltyButton.setAttribute('aria-pressed',loyaltyFreeKey?'true':'false');loyaltyButton.title=loyaltyFreeKey?'Click again to cancel the loyalty free drink':'Select the loyalty free drink';
  }
  function renderChoices(container,choices,selected,type){container.innerHTML=choices.map(choice=>{const value=typeof choice==='string'?choice:choice.value,label=typeof choice==='string'?choice:choice.label,charge=typeof choice==='string'?'':choice.charge===0?'Free':`+ LKR ${money(choice.charge)}`;return `<button type="button" class="custom-choice ${value===selected?'selected':''}" data-choice-type="${type}" data-value="${escapeHtml(value)}"><b>${escapeHtml(label)}</b>${charge?`<small>${charge}</small>`:''}</button>`;}).join('');}
  function updateCustomization(){
    sizeOptions.querySelectorAll('.size-option').forEach(button=>button.classList.toggle('selected',selectedVariant&&Number(button.dataset.id)===selectedVariant.id));
    renderChoices(bobaOptions,bobaChoices,selectedBoba.value,'boba');renderChoices(toppingOptions,toppingChoices,selectedTopping.value,'topping');renderChoices(sugarOptions,sugarChoices,selectedSugar,'sugar');renderChoices(iceOptions,iceChoices,selectedIce,'ice');
    customPrice.textContent=money((selectedVariant?.price||0)+selectedBoba.charge+selectedTopping.charge);addButton.disabled=!selectedVariant;
  }
  function openModal(product){
    const fixedSize=fixedSizeCategories[product.category],fruitSalad=product.category==='Fruit Salad',bobaOnly=['Ice Cream','Sundae'].includes(product.category),sugarOnly=product.category==='Hot Drinks';selectedProduct=product;selectedVariant=fixedSize?(product.variants.find(v=>v.size===fixedSize&&v.stock>0)||null):null;selectedBoba=bobaChoices[0];selectedTopping=toppingChoices[0];selectedSugar='Normal';selectedIce='Normal Ice';modalTitle.textContent=product.name;sizeGroup.hidden=Boolean(fixedSize);bobaGroup.hidden=sugarOnly;primaryColumn.hidden=sugarOnly;sugarGroup.hidden=bobaOnly||fruitSalad;iceGroup.hidden=bobaOnly||sugarOnly||fruitSalad;toppingGroup.hidden=!fruitSalad;optionsColumn.hidden=bobaOnly;bobaStep.textContent=fruitSalad?'1':bobaOnly?'1':fixedSize?'1':'2';sugarStep.textContent=sugarOnly?'1':fixedSize?'2':'3';iceStep.textContent=fixedSize?'3':'4';modalBox.classList.toggle('boba-only',bobaOnly);modalBox.classList.toggle('sugar-only',sugarOnly);modalBox.classList.toggle('fruit-salad',fruitSalad);const fixedVariant=fixedSize?product.variants.find(v=>v.size===fixedSize):null,stockText=fixedVariant?` Available stock: ${fixedVariant.stock}.`:'';helpText.textContent=(fruitSalad?'Choose boba and a fruit salad topping.':bobaOnly?'Choose your boba level.':sugarOnly?'Choose your sugar level.':fixedSize?'Choose boba, sweetness and ice level.':'Choose the cup size, boba, sweetness and ice level.')+stockText;
    sizeOptions.innerHTML=product.variants.map(v=>`<button type="button" class="size-option" data-id="${v.id}" ${v.stock<=0?'disabled':''}><span class="size-letter">${escapeHtml(v.size)}</span><span><b>LKR ${money(v.price)}</b><small>${v.stock>0?`Stock ${v.stock}`:'Out of stock'}</small></span></button>`).join('');
    updateCustomization();modal.hidden=false;document.body.classList.add('modal-open');(selectedVariant?addButton:sizeOptions.querySelector('button:not(:disabled)'))?.focus();
  }
  function closeModal(){modal.hidden=true;document.body.classList.remove('modal-open');selectedProduct=null;selectedVariant=null;}
  function addCustomizedDrink(){
    if(!selectedProduct||!selectedVariant)return;const key=[selectedVariant.id,selectedBoba.value,selectedTopping.value,selectedSugar,selectedIce].join('|'),old=cart.get(key);
    if(old){if(old.qty<old.stock)old.qty++;}else cart.set(key,{...selectedVariant,key,name:selectedProduct.name,category:selectedProduct.category,price:selectedVariant.price+selectedBoba.charge+selectedTopping.charge,boba_level:selectedBoba.value,topping_level:selectedTopping.value,sugar_level:selectedSugar,ice_level:selectedIce,qty:1});closeModal();render();
  }
  function addDirectProduct(product){
    const variant=product.variants.find(v=>v.size==='M'&&v.stock>0)||product.variants.find(v=>v.stock>0);
    if(!variant){document.getElementById('checkoutError').textContent='Product is out of stock.';return;}
    const key=[variant.id,'No Boba','Normal','Normal Ice'].join('|'),old=cart.get(key);
    if(old){if(old.qty<old.stock)old.qty++;}else cart.set(key,{...variant,key,name:product.name,category:product.category,price:variant.price,boba_level:'No Boba',topping_level:'No Topping',sugar_level:'Normal',ice_level:'Normal Ice',qty:1});
    render();
  }
  cards.forEach(card=>card.addEventListener('click',()=>openModal(JSON.parse(card.dataset.product))));
  sizeOptions.addEventListener('click',e=>{const button=e.target.closest('.size-option');if(!button||button.disabled)return;selectedVariant=selectedProduct.variants.find(v=>v.id===Number(button.dataset.id));updateCustomization();});
  [bobaOptions,toppingOptions,sugarOptions,iceOptions].forEach(container=>container.addEventListener('click',e=>{const button=e.target.closest('.custom-choice');if(!button)return;const{choiceType,value}=button.dataset;if(choiceType==='boba')selectedBoba=bobaChoices.find(x=>x.value===value);if(choiceType==='topping')selectedTopping=toppingChoices.find(x=>x.value===value);if(choiceType==='sugar')selectedSugar=value;if(choiceType==='ice')selectedIce=value;updateCustomization();}));
  addButton.addEventListener('click',addCustomizedDrink);modal.querySelectorAll('[data-close-modal]').forEach(x=>x.addEventListener('click',closeModal));document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!modal.hidden)closeModal();});
  cartEl.addEventListener('click',e=>{const b=e.target.closest('button');if(!b)return;const x=cart.get(b.dataset.key);if(!x)return;if(b.dataset.act==='plus'&&x.qty<x.stock)x.qty++;if(b.dataset.act==='minus'){x.qty--;if(x.qty<=0)cart.delete(b.dataset.key);}if(b.dataset.act==='remove')cart.delete(b.dataset.key);render();});
  cartEl.addEventListener('change',e=>{if(!e.target.matches('.qty-direct'))return;const x=cart.get(e.target.dataset.key);if(!x)return;x.qty=Math.max(1,Math.min(x.stock,parseInt(e.target.value,10)||1));render();});
  ['discount','paidAmount'].forEach(id=>document.getElementById(id).addEventListener('input',render));document.getElementById('paymentMethod').addEventListener('change',e=>{if(e.target.value!=='cash'){document.getElementById('paidAmount').value=getTotals().total.toFixed(2);render();}});document.getElementById('clearCart').addEventListener('click',()=>{cart.clear();loyaltyFreeKey=null;localStorage.removeItem(CART_STORAGE_KEY);render();});
  document.getElementById('productSearch').addEventListener('input',filter);document.querySelectorAll('.chip').forEach(ch=>ch.addEventListener('click',()=>{document.querySelectorAll('.chip').forEach(x=>x.classList.remove('active'));ch.classList.add('active');filter();}));
  const holdOrderBtn=document.getElementById('holdOrderBtn'),resumeOrderBtn=document.getElementById('resumeOrderBtn'),heldOrderCount=document.getElementById('heldOrderCount'),loyaltyFreeBtn=document.getElementById('loyaltyFreeBtn');
  const chooserModal=document.getElementById('orderChooserModal'),chooserTitle=document.getElementById('orderChooserTitle'),chooserHelp=document.getElementById('orderChooserHelp'),chooserList=document.getElementById('orderChooserList');let chooserAction=null;
  const closeChooser=()=>{chooserModal.hidden=true;chooserList.innerHTML='';chooserAction=null;document.body.classList.remove('chooser-open');};
  const openChooser=(title,help,options,action)=>{chooserTitle.textContent=title;chooserHelp.textContent=help;chooserList.innerHTML=options.map((x,i)=>`<button type="button" data-choice-index="${i}"><span class="chooser-number">${i+1}</span><span><b>${escapeHtml(x.title)}</b><small>${escapeHtml(x.detail||'')}</small></span><span class="chooser-arrow">›</span></button>`).join('');chooserAction=action;chooserModal.hidden=false;document.body.classList.add('chooser-open');chooserList.querySelector('button')?.focus();};
  chooserModal.querySelectorAll('[data-chooser-close]').forEach(x=>x.addEventListener('click',closeChooser));chooserList.addEventListener('click',e=>{const button=e.target.closest('[data-choice-index]');if(!button||!chooserAction)return;const action=chooserAction,index=Number(button.dataset.choiceIndex);closeChooser();action(index);});
  const readHeld=()=>{try{return JSON.parse(localStorage.getItem(HELD_STORAGE_KEY)||'[]')}catch(e){return[]}};
  const updateHeldCount=()=>{heldOrderCount.textContent=readHeld().length;};
  holdOrderBtn.addEventListener('click',()=>{if(!cart.size)return;const held=readHeld();held.push({id:Date.now(),time:new Date().toLocaleTimeString('en-LK',{hour:'2-digit',minute:'2-digit'}),items:[...cart.values()],discount:document.getElementById('discount').value,loyaltyFreeKey});localStorage.setItem(HELD_STORAGE_KEY,JSON.stringify(held));cart.clear();loyaltyFreeKey=null;localStorage.removeItem(CART_STORAGE_KEY);document.getElementById('discount').value='0';updateHeldCount();render();});
  resumeOrderBtn.addEventListener('click',()=>{const held=readHeld();if(!held.length){document.getElementById('checkoutError').textContent='No held orders are available.';return;}openChooser('Resume Held Order','Select the order you want to return to the Current Bill.',held.map(x=>({title:`Held at ${x.time}`,detail:`${x.items.length} item type${x.items.length===1?'':'s'} • ${x.items.reduce((s,i)=>s+i.qty,0)} total quantity`})),choice=>{if(!held[choice])return;cart.clear();held[choice].items.forEach(x=>cart.set(x.key,x));document.getElementById('discount').value=held[choice].discount||0;loyaltyFreeKey=held[choice].loyaltyFreeKey||null;held.splice(choice,1);localStorage.setItem(HELD_STORAGE_KEY,JSON.stringify(held));updateHeldCount();render();});});
  loyaltyFreeBtn.addEventListener('click',()=>{if(loyaltyFreeKey){loyaltyFreeKey=null;render();return;}if(!cart.size){document.getElementById('checkoutError').textContent='Add the free drink to the bill first.';return;}const items=[...cart.values()];openChooser('Select Loyalty Free Drink','Choose one drink for the completed 5-stamp loyalty card.',items.map(x=>({title:x.name,detail:`${x.size||''} • LKR ${money(x.price)}${x.qty>1?` • Qty ${x.qty}`:''}`})),choice=>{if(!items[choice])return;loyaltyFreeKey=items[choice].key;render();});});
  function filter(){const q=document.getElementById('productSearch').value.toLowerCase(),c=document.querySelector('.chip.active').dataset.category;cards.forEach(x=>x.hidden=!(x.dataset.name.includes(q)&&(c==='all'||x.dataset.category===c)));document.querySelectorAll('.product-category-row').forEach(row=>{row.hidden=![...row.querySelectorAll('.product-card')].some(card=>!card.hidden);});}
  const adminApprovalOverlay=document.getElementById('adminApprovalOverlay');
  const adminApprovalTitle=document.getElementById('adminApprovalTitle');
  const adminApprovalText=document.getElementById('adminApprovalText');
  const adminApprovalUsername=document.getElementById('adminApprovalUsername');
  const adminApprovalPassword=document.getElementById('adminApprovalPassword');
  const adminApprovalError=document.getElementById('adminApprovalError');
  const adminApprovalConfirm=document.getElementById('adminApprovalConfirm');
  const adminApprovalCancel=document.getElementById('adminApprovalCancel');
  let discountApprovalToken='';

  const requestAdminApproval=(action,reference,title,text)=>new Promise(resolve=>{
    let settled=false;
    const close=result=>{
      if(settled)return;settled=true;
      adminApprovalOverlay.classList.remove('open');
      adminApprovalOverlay.setAttribute('aria-hidden','true');
      document.body.classList.remove('admin-approval-open');
      adminApprovalConfirm.disabled=false;
      adminApprovalConfirm.textContent='Approve';
      cleanup();
      resolve(result);
    };
    const onCancel=()=>close(null);
    const onBackdrop=e=>{if(e.target===adminApprovalOverlay)close(null);};
    const onKey=e=>{if(e.key==='Escape')close(null);};
    const onConfirm=async()=>{
      const username=adminApprovalUsername.value.trim(),password=adminApprovalPassword.value;
      adminApprovalError.textContent='';
      if(!username||!password){adminApprovalError.textContent='Enter the Admin username and password.';return;}
      adminApprovalConfirm.disabled=true;adminApprovalConfirm.textContent='Checking...';
      try{
        const res=await fetch(`${window.POS_BASE_URL}/api/admin_approval.php`,{
          method:'POST',
          headers:{'Content-Type':'application/json','X-CSRF-Token':window.POS_CSRF},
          body:JSON.stringify({action,reference,username,password})
        });
        const data=await res.json();
        if(!res.ok||!data.ok)throw new Error(data.error||'Admin approval failed.');
        close(data.token);
      }catch(err){
        adminApprovalError.textContent=err.message||'Admin approval failed.';
        adminApprovalConfirm.disabled=false;adminApprovalConfirm.textContent='Approve';
        adminApprovalPassword.select();
      }
    };
    const cleanup=()=>{
      adminApprovalCancel.removeEventListener('click',onCancel);
      adminApprovalOverlay.removeEventListener('click',onBackdrop);
      document.removeEventListener('keydown',onKey);
      adminApprovalConfirm.removeEventListener('click',onConfirm);
    };
    adminApprovalTitle.textContent=title;
    adminApprovalText.textContent=text;
    adminApprovalUsername.value='';
    adminApprovalPassword.value='';
    adminApprovalError.textContent='';
    adminApprovalOverlay.classList.add('open');
    adminApprovalOverlay.setAttribute('aria-hidden','false');
    document.body.classList.add('admin-approval-open');
    adminApprovalCancel.addEventListener('click',onCancel);
    adminApprovalOverlay.addEventListener('click',onBackdrop);
    document.addEventListener('keydown',onKey);
    adminApprovalConfirm.addEventListener('click',onConfirm);
    setTimeout(()=>adminApprovalUsername.focus(),0);
  });

  const saleConfirmModal=document.getElementById('saleConfirmModal'),confirmSaleBtn=document.getElementById('confirmSaleBtn'),cancelSaleConfirm=document.getElementById('cancelSaleConfirm'),checkoutBtn=document.getElementById('checkoutBtn');
  const closeSaleConfirm=()=>{saleConfirmModal.hidden=true;document.body.classList.remove('sale-confirm-open');checkoutBtn.focus();};
  checkoutBtn.addEventListener('click',async()=>{const error=document.getElementById('checkoutError');error.textContent='';const t=getTotals();if(!cart.size){error.textContent='Add at least one product.';return;}if(t.paid<t.total){error.textContent='Paid amount is less than total.';return;}discountApprovalToken='';const discountPct=t.subtotal>0?(t.manualDiscount/t.subtotal)*100:0;if(discountPct>HIGH_DISCOUNT_THRESHOLD+0.00001){const reference=`${t.subtotal.toFixed(2)}|${t.manualDiscount.toFixed(2)}`;const token=await requestAdminApproval('high_discount',reference,'High Discount Approval',`This bill has a ${discountPct.toFixed(1)}% manual discount (LKR ${money(t.manualDiscount)}). Admin approval is required above ${HIGH_DISCOUNT_THRESHOLD}%.`);if(!token){error.textContent='High discount approval was cancelled.';return;}discountApprovalToken=token;}saleConfirmModal.hidden=false;document.body.classList.add('sale-confirm-open');confirmSaleBtn.focus();});
  cancelSaleConfirm.addEventListener('click',closeSaleConfirm);saleConfirmModal.querySelector('[data-sale-confirm-close]').addEventListener('click',closeSaleConfirm);document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!saleConfirmModal.hidden)closeSaleConfirm();});
  confirmSaleBtn.addEventListener('click',async()=>{const error=document.getElementById('checkoutError'),t=getTotals(),items=[...cart.values()],loyaltyFreeIndex=loyaltyFreeKey?items.findIndex(x=>x.key===loyaltyFreeKey):-1;if(!cart.size||t.paid<t.total){closeSaleConfirm();error.textContent=!cart.size?'Add at least one product.':'Paid amount is less than total.';return;}confirmSaleBtn.disabled=true;confirmSaleBtn.textContent='Finalizing...';checkoutBtn.disabled=true;checkoutBtn.textContent='Processing...';try{const res=await fetch(`${window.POS_BASE_URL}/api/checkout.php`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.POS_CSRF},body:JSON.stringify({items:items.map(x=>({id:x.id,qty:x.qty,boba_level:x.boba_level,topping_level:x.topping_level||'No Topping',sugar_level:x.sugar_level,ice_level:x.ice_level})),discount:t.manualDiscount,loyalty_free_index:loyaltyFreeIndex,payment_method:document.getElementById('paymentMethod').value,paid_amount:t.paid,approval_token:discountApprovalToken,checkout_token:String(window.POS_CHECKOUT_TOKEN||'')})});const data=await res.json();if(!res.ok)throw new Error(data.error||'Checkout failed.');localStorage.setItem(LAST_SALE_KEY,data.sale_id);const frame=document.createElement('iframe');frame.style.position='fixed';frame.style.width='1px';frame.style.height='1px';frame.style.opacity='0';frame.style.pointerEvents='none';frame.style.border='0';frame.setAttribute('aria-hidden','true');frame.src=`${window.POS_BASE_URL}/receipt.php?id=${data.sale_id}&auto=1&embedded=1`;document.body.appendChild(frame);let finished=false;const goNext=()=>{if(finished)return;finished=true;discountApprovalToken='';cart.clear();localStorage.removeItem(CART_STORAGE_KEY);location.replace(`${window.POS_BASE_URL}/pos.php`);};window.addEventListener('message',function receiptDone(ev){if(ev.origin===location.origin&&ev.data==='misty-receipt-print-finished'){window.removeEventListener('message',receiptDone);goNext();}});setTimeout(goNext,8000);}catch(e){discountApprovalToken='';closeSaleConfirm();error.textContent=e.message;confirmSaleBtn.disabled=false;confirmSaleBtn.textContent='Yes, Finalize & Print';checkoutBtn.disabled=false;checkoutBtn.textContent='Complete Sale & Print';}});
  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!chooserModal.hidden){closeChooser();return;}if(e.key==='F2'){e.preventDefault();document.getElementById('clearCart').focus();}if(e.key==='F4'){e.preventDefault();document.getElementById('paymentMethod').focus();}if(e.key==='F8'){e.preventDefault();holdOrderBtn.click();}if(e.key==='F9'){e.preventDefault();resumeOrderBtn.click();}if(e.key==='Enter'&&!modal.hidden)addButton.click();});
  restoreCart();
  updateHeldCount();
  render();
})();
