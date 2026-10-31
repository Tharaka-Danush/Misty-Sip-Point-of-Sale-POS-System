<?php
require_once __DIR__.'/includes/bootstrap.php';
require_login();
$user=auth_user();
if(($user['role']??'')!=='cashier'){header('Location: '.base_url('index.php'));exit;}
$pageTitle='Sale Summary';
$pageSubtitle='Held bills, recent sales and bill actions in one place.';
$bodyClass='pos-page';
$extraStyles=['assets/css/sale-summary.css?v=1'];
$todayStart=date('Y-m-d 00:00:00');
$tomorrowStart=date('Y-m-d 00:00:00',strtotime('+1 day'));
$stmt=db()->prepare("SELECT s.id,s.bill_no,s.subtotal,s.discount,s.loyalty_discount,s.total,s.payment_method,s.created_at,s.is_void,s.void_reason,s.voided_at FROM sales s WHERE s.user_id=? AND s.created_at>=? AND s.created_at<? ORDER BY s.id DESC");
$stmt->execute([(int)$user['id'],$todayStart,$tomorrowStart]);
$sales=$stmt->fetchAll();
require __DIR__.'/includes/header.php';
?>
<section class="sale-summary-page">
  <section class="panel">
    <div class="summary-head"><div><h2>Held Bills</h2><p>Orders placed on hold from this cashier account.</p></div><span class="summary-count" id="heldSummaryCount">0</span></div>
    <div class="held-table-wrap">
      <table class="held-orders-table">
        <thead>
          <tr>
            <th>Held Bill</th>
            <th>Time</th>
            <th>Items</th>
            <th>Qty</th>
            <th>Total</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody id="heldOrdersGrid">
          <tr><td class="empty-held" colspan="6">Loading held bills…</td></tr>
        </tbody>
      </table>
    </div>
  </section>

  <section class="panel sale-summary-table">
    <div class="summary-head"><div><h2>Today's Sales</h2><p>Completed and voided bills from today only.</p></div><span class="summary-count"><?= count($sales) ?></span></div>
    <div class="table-wrap"><table>
      <thead><tr><th>Bill</th><th>Payment</th><th>Subtotal</th><th>Discount</th><th>Total</th><th>Date</th><th>Status</th><th>Action</th><th>Reprint</th></tr></thead>
      <tbody>
      <?php foreach($sales as $sale): $discount=(float)$sale['discount']+(float)$sale['loyalty_discount']; ?>
        <tr>
          <td><strong><?= e($sale['bill_no']) ?></strong></td>
          <td><span class="badge"><?= e(ucfirst((string)$sale['payment_method'])) ?></span></td>
          <td>LKR <?= number_format((float)$sale['subtotal'],2) ?></td>
          <td>LKR <?= number_format($discount,2) ?></td>
          <td><strong>LKR <?= number_format((float)$sale['total'],2) ?></strong></td>
          <td><?= date('d M Y, h:i A',strtotime((string)$sale['created_at'])) ?></td>
          <td><?php if(!empty($sale['is_void'])): ?><span class="status-void">VOID</span><?php else: ?><span class="status-completed">Completed</span><?php endif; ?></td>
          <td class="action-cell"><?php if(empty($sale['is_void'])): ?><button class="void-btn" type="button" data-sale-id="<?= e($sale['id']) ?>" data-bill="<?= e($sale['bill_no']) ?>">Void</button><?php else: ?><span class="action-na">—</span><?php endif; ?></td>
          <td class="reprint-cell"><a class="receipt-link" href="receipt.php?id=<?= e($sale['id']) ?>">Reprint</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$sales): ?><tr><td class="empty" colspan="9">No sales today.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </section>
</section>

<div class="remove-overlay" id="removeOverlay" aria-hidden="true">
  <div class="remove-modal" role="dialog" aria-modal="true" aria-labelledby="removeHeldTitle">
    <div class="remove-modal-icon" aria-hidden="true">!</div>
    <h3 id="removeHeldTitle">Remove Held Bill</h3>
    <p id="removeHeldText">Enter a reason for removing this held bill.</p>
    <textarea id="removeHeldReason" maxlength="250" placeholder="Reason for removing this held bill"></textarea>
    <div class="remove-error" id="removeHeldError"></div>
    <div class="remove-modal-actions">
      <button class="remove-cancel" type="button" id="removeHeldCancel">Cancel</button>
      <button class="remove-confirm" type="button" id="removeHeldConfirm">Remove Bill</button>
    </div>
  </div>
</div>

<div class="void-overlay" id="voidOverlay" aria-hidden="true">
  <div class="void-modal" role="dialog" aria-modal="true" aria-labelledby="voidTitle">
    <span class="void-approval-mark">ADMIN APPROVAL REQUIRED</span>
    <h3 id="voidTitle">Void Bill</h3>
    <p id="voidBillText">Enter a reason for voiding this bill.</p>
    <form method="post" action="cashier_void_sale.php" id="voidForm">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="sale_id" id="voidSaleId">
      <input type="hidden" name="approval_token" id="voidApprovalToken">
      <label>Void Reason<textarea name="reason" id="voidReason" required maxlength="250" placeholder="Reason for voiding this bill"></textarea></label>
      <div class="void-admin-grid">
        <label>Admin Username<input type="text" id="voidAdminUsername" autocomplete="username"></label>
        <label>Admin Password<input type="password" id="voidAdminPassword" autocomplete="current-password"></label>
      </div>
      <p class="void-approval-error" id="voidApprovalError"></p>
      <div class="void-modal-actions"><button class="void-cancel" type="button" id="voidCancel">Cancel</button><button class="void-confirm" type="submit" id="voidConfirmBtn">Approve &amp; Void</button></div>
    </form>
  </div>
</div>
<script>
(function(){
  const userId=<?= (int)$user['id'] ?>;
  const heldKey=`mistySipHeldOrdersV2:${userId}`;
  const resumeKey=`mistySipResumeHeldV2:${userId}`;
  const grid=document.getElementById('heldOrdersGrid'),count=document.getElementById('heldSummaryCount');
  const escapeHtml=v=>{const d=document.createElement('div');d.textContent=v??'';return d.innerHTML;};
  const money=n=>Number(n||0).toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2});
  function readHeld(){try{const v=JSON.parse(localStorage.getItem(heldKey)||'[]');return Array.isArray(v)?v:[]}catch(e){return[]}}
  function saveHeld(v){localStorage.setItem(heldKey,JSON.stringify(v));renderHeld();}
  let pendingRemoveIndex=null;
  const removeOverlay=document.getElementById('removeOverlay');
  const removeReason=document.getElementById('removeHeldReason');
  const removeError=document.getElementById('removeHeldError');
  const removeText=document.getElementById('removeHeldText');

  function renderHeld(){
    const held=readHeld();count.textContent=held.length;
    if(!held.length){
      grid.innerHTML='<tr><td class="empty-held" colspan="6">No held bills right now.</td></tr>';
      return;
    }
    grid.innerHTML=held.map((h,i)=>{
      const qty=(h.items||[]).reduce((s,x)=>s+Number(x.qty||0),0);
      const subtotal=(h.items||[]).reduce((s,x)=>s+(Number(x.price||0)*Number(x.qty||0)),0);
      const disc=Math.min(Math.max(Number(h.discount)||0,0),subtotal);
      const total=subtotal-disc;
      const names=(h.items||[]).map(x=>escapeHtml(x.name)).join(', ');
      return `<tr class="held-row">
        <td><strong>Held Bill ${i+1}</strong></td>
        <td>${escapeHtml(h.time||'—')}</td>
        <td class="held-items-cell">${names||'Held items'}</td>
        <td><strong>${qty}</strong></td>
        <td><strong>LKR ${money(total)}</strong></td>
        <td class="held-row-actions">
          <button class="resume-held" type="button" data-index="${i}">Resume</button>
          <button class="delete-held" type="button" data-index="${i}">Remove</button>
        </td>
      </tr>`;
    }).join('');
  }

  function openRemoveModal(index){
    const held=readHeld(), item=held[index];
    if(!item)return;
    pendingRemoveIndex=index;
    removeReason.value='';
    removeError.textContent='';
    removeText.textContent=`Enter a reason for removing Held Bill ${index+1}.`;
    removeOverlay.classList.add('open');
    removeOverlay.setAttribute('aria-hidden','false');
    document.body.classList.add('summary-modal-open');
    setTimeout(()=>removeReason.focus(),0);
  }
  function closeRemoveModal(){
    removeOverlay.classList.remove('open');
    removeOverlay.setAttribute('aria-hidden','true');
    document.body.classList.remove('summary-modal-open');
    pendingRemoveIndex=null;
    removeReason.value='';
    removeError.textContent='';
  }

  grid.addEventListener('click',e=>{
    const btn=e.target.closest('button[data-index]');
    if(!btn)return;
    const i=Number(btn.dataset.index),held=readHeld(),item=held[i];
    if(!item)return;
    if(btn.classList.contains('resume-held')){
      localStorage.setItem(resumeKey,JSON.stringify(item));
      held.splice(i,1);
      localStorage.setItem(heldKey,JSON.stringify(held));
      location.href='pos.php';
      return;
    }
    if(btn.classList.contains('delete-held')) openRemoveModal(i);
  });

  document.getElementById('removeHeldCancel').addEventListener('click',closeRemoveModal);
  document.getElementById('removeHeldConfirm').addEventListener('click',()=>{
    const reason=removeReason.value.trim();
    if(!reason){
      removeError.textContent='Please enter a reason before removing this held bill.';
      removeReason.focus();
      return;
    }
    if(pendingRemoveIndex===null)return;
    const held=readHeld();
    const removed=held[pendingRemoveIndex];
    if(!removed){closeRemoveModal();renderHeld();return;}

    // Keep a small local audit trail without changing the database.
    try{
      const auditKey=`mistySipHeldRemovalLogV1:${userId}`;
      const log=JSON.parse(localStorage.getItem(auditKey)||'[]');
      log.unshift({
        removed_at:new Date().toISOString(),
        held_time:removed.time||'',
        reason:reason,
        items:Array.isArray(removed.items)?removed.items.length:0
      });
      localStorage.setItem(auditKey,JSON.stringify(log.slice(0,100)));
    }catch(e){}

    held.splice(pendingRemoveIndex,1);
    localStorage.setItem(heldKey,JSON.stringify(held));
    closeRemoveModal();
    renderHeld();
  });
  removeOverlay.addEventListener('click',e=>{if(e.target===removeOverlay)closeRemoveModal();});
  document.addEventListener('keydown',e=>{
    if(e.key==='Escape'&&removeOverlay.classList.contains('open')) closeRemoveModal();
  });

  renderHeld();

  const overlay=document.getElementById('voidOverlay'),saleId=document.getElementById('voidSaleId'),reason=document.getElementById('voidReason'),billText=document.getElementById('voidBillText');
  const voidForm=document.getElementById('voidForm'),voidApprovalToken=document.getElementById('voidApprovalToken'),voidAdminUsername=document.getElementById('voidAdminUsername'),voidAdminPassword=document.getElementById('voidAdminPassword'),voidApprovalError=document.getElementById('voidApprovalError'),voidConfirmBtn=document.getElementById('voidConfirmBtn');
  let voidApproved=false;
  document.querySelectorAll('.void-btn').forEach(btn=>btn.addEventListener('click',()=>{saleId.value=btn.dataset.saleId;billText.textContent=`Reason for voiding ${btn.dataset.bill}:`;reason.value='';voidApprovalToken.value='';voidAdminUsername.value='';voidAdminPassword.value='';voidApprovalError.textContent='';voidApproved=false;overlay.classList.add('open');overlay.setAttribute('aria-hidden','false');setTimeout(()=>reason.focus(),0);}));
  function closeVoid(){overlay.classList.remove('open');overlay.setAttribute('aria-hidden','true');voidApprovalError.textContent='';}
  voidForm.addEventListener('submit',async e=>{
    if(voidApproved)return;
    e.preventDefault();
    const why=reason.value.trim(),username=voidAdminUsername.value.trim(),password=voidAdminPassword.value;
    voidApprovalError.textContent='';
    if(!why){voidApprovalError.textContent='Enter a reason for the void.';reason.focus();return;}
    if(!username||!password){voidApprovalError.textContent='Enter the Admin username and password.';voidAdminUsername.focus();return;}
    voidConfirmBtn.disabled=true;voidConfirmBtn.textContent='Checking...';
    try{
      const res=await fetch(<?= json_encode(base_url('api/admin_approval.php')) ?>,{
        method:'POST',
        headers:{'Content-Type':'application/json','X-CSRF-Token':<?= json_encode(csrf_token()) ?>},
        body:JSON.stringify({action:'void_sale',reference:String(saleId.value),username,password})
      });
      const data=await res.json();
      if(!res.ok||!data.ok)throw new Error(data.error||'Admin approval failed.');
      voidApprovalToken.value=data.token;
      voidApproved=true;
      voidForm.submit();
    }catch(err){
      voidApprovalError.textContent=err.message||'Admin approval failed.';
      voidConfirmBtn.disabled=false;voidConfirmBtn.textContent='Approve & Void';
      voidAdminPassword.select();
    }
  });
  document.getElementById('voidCancel').addEventListener('click',closeVoid);overlay.addEventListener('click',e=>{if(e.target===overlay)closeVoid();});document.addEventListener('keydown',e=>{if(e.key==='Escape')closeVoid();});
})();
</script>
<?php include __DIR__.'/includes/footer.php'; ?>
