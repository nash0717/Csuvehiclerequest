<?php
// These variables come from Schedules.php where this patch is included
global $drivers, $vehicles, $allDrivers, $allVehicles, $driverVehicleMap, $driverVehicleMapJson, $pdo;
/*
 * ════════════════════════════════════════════════════════════
 *  PATCH: Schedules.php — Custom Driver/Vehicle Selects
 *  with auto-fill from driver_vehicle_assignments table
 * ════════════════════════════════════════════════════════════
 *
 *  HOW TO APPLY:
 *  1. In Schedules.php, after $vehicles / $drivers are fetched,
 *     ADD the following PHP block (marked STEP A).
 *
 *  2. Replace the APPROVE modal HTML with the one below (STEP B).
 *     Also replace the CHANGE ASSIGNMENT modal similarly (STEP C).
 *
 *  3. Replace the JS section for conflict detection with the
 *     updated block (STEP D) — adds auto-vehicle fill logic.
 * ════════════════════════════════════════════════════════════
 */

/* ══════════════════════
   STEP A — Add after your $vehicles / $drivers fetch queries
   (around line ~400 in Schedules.php)
══════════════════════ */
$dvaStmt = $pdo->query("
    SELECT driver_id, vehicle_id
    FROM driver_vehicle_assignments
");
$driverVehicleMap = [];
foreach ($dvaStmt->fetchAll() as $row) {
    $driverVehicleMap[(int)$row['driver_id']] = (int)$row['vehicle_id'];
}
// Pass to JS as a JSON map
$driverVehicleMapJson = json_encode($driverVehicleMap);

/* ══════════════════════════════════════════════════════
   STEP B — Replace the APPROVE modal in Schedules.php
   (the <div class="modal fade" id="approveModal"> block)
   with the following:
══════════════════════════════════════════════════════ */
?>
<!-- APPROVE (with Custom Selects + Auto-Vehicle Fill) -->
<div class="modal fade" id="approveModal" tabindex="-1">
<div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header mh-maroon">
    <h5 class="modal-title"><i class="bi bi-check-circle me-2"></i>Approve & Assign</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
  </div>
  <form method="POST" action="Schedules.php" id="approveForm">
    <input type="hidden" name="action" value="approve">
    <input type="hidden" name="schedule_id" id="apr_sid">
    <input type="hidden" name="vehicle_id"  id="apr_vehicle_id_hidden">
    <input type="hidden" name="driver_id"   id="apr_driver_id_hidden">
    <div class="modal-body">
      <!-- Request summary -->
      <div class="sbox mb-3">
        <div class="sbox-title"><i class="bi bi-info-circle me-1"></i>Request Details</div>
        <div class="row g-2">
          <div class="col-md-3"><div style="font-size:.75rem;color:#888">Requestor</div><div class="fw-semibold" id="apr_uname">—</div></div>
          <div class="col-md-3"><div style="font-size:.75rem;color:#888">Date Range</div><div class="fw-semibold" id="apr_dates">—</div></div>
          <div class="col-md-3"><div style="font-size:.75rem;color:#888">Time</div><div class="fw-semibold" id="apr_time">—</div></div>
          <div class="col-md-3"><div style="font-size:.75rem;color:#888">Passengers</div><div class="fw-semibold" id="apr_pax">—</div></div>
        </div>
      </div>

      <!-- Assign section -->
      <div class="sbox">
        <div class="sbox-title"><i class="bi bi-truck-front me-1"></i>Assign Driver & Vehicle</div>
        <p class="text-muted mb-3" style="font-size:.82rem">
          <i class="bi bi-stars me-1 text-warning"></i>
          Selecting a driver will <strong>auto-fill their default vehicle</strong> from assignments.
          Options marked <strong>[BOOKED]</strong> are already reserved.
        </p>
        <div class="row g-3">
          <!-- Driver Custom Select -->
          <div class="col-md-6">
            <label class="form-label fw-semibold">Driver <span class="text-danger">*</span></label>
            <div class="custom-select-wrap" id="apr_drv_wrap">
              <div class="custom-select-trigger" id="apr_drv_trigger" tabindex="0" onclick="toggleAprCS('drv')">
                <span class="custom-select-placeholder" id="apr_drv_display">— Select Driver —</span>
              </div>
              <div class="custom-select-dropdown" id="apr_drv_dropdown">
                <div class="cs-search-wrap">
                  <i class="bi bi-search" style="color:#aaa;font-size:.8rem"></i>
                  <input class="cs-search-input" placeholder="Search driver…" oninput="filterAprCS('drv',this.value)" id="apr_drv_search">
                </div>
                <div id="apr_drv_options">
                  <?php foreach($drivers as $d):
                    $vid = $driverVehicleMap[$d['driver_id']] ?? 0;
                    $hasV = $vid > 0;
                    $ini = strtoupper(implode('', array_map(fn($p)=>$p[0], explode(' ', trim($d['driver_name']??'D')))));
                    $ini = substr($ini,0,2);
                  ?>
                  <div class="cs-option"
                       data-value="<?= $d['driver_id'] ?>"
                       data-label="<?= htmlspecialchars($d['driver_name'], ENT_QUOTES) ?>"
                       data-sub="<?= htmlspecialchars($d['license_number']??'', ENT_QUOTES) ?>"
                       data-default-vehicle="<?= $vid ?>"
                       data-initials="<?= htmlspecialchars($ini, ENT_QUOTES) ?>"
                       onclick="selectAprDriver(this)">
                    <div class="cs-opt-icon driver-ico"><?= htmlspecialchars($ini) ?></div>
                    <div style="flex:1;min-width:0">
                      <div class="cs-opt-label"><?= htmlspecialchars($d['driver_name']) ?></div>
                      <div class="cs-opt-sub"><?= htmlspecialchars($d['license_number']??'No license') ?></div>
                    </div>
                    <?php if($hasV): ?>
                    <span class="cs-opt-badge badge-assigned"><i class="bi bi-truck-front me-1"></i>Has vehicle</span>
                    <?php else: ?>
                    <span class="cs-opt-badge badge-unassigned">No vehicle</span>
                    <?php endif; ?>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
            <div id="apr_drv_msg" class="mt-1"></div>
          </div>

          <!-- Vehicle Custom Select -->
          <div class="col-md-6">
            <label class="form-label fw-semibold">Vehicle <span class="text-danger">*</span></label>
            <div class="custom-select-wrap" id="apr_veh_wrap">
              <div class="custom-select-trigger" id="apr_veh_trigger" tabindex="0" onclick="toggleAprCS('veh')">
                <span class="custom-select-placeholder" id="apr_veh_display">— Select Vehicle —</span>
              </div>
              <div class="custom-select-dropdown" id="apr_veh_dropdown">
                <div class="cs-search-wrap">
                  <i class="bi bi-search" style="color:#aaa;font-size:.8rem"></i>
                  <input class="cs-search-input" placeholder="Search vehicle…" oninput="filterAprCS('veh',this.value)" id="apr_veh_search">
                </div>
                <div id="apr_veh_options">
                  <?php foreach($vehicles as $v): ?>
                  <div class="cs-option"
                       data-value="<?= $v['vehicle_id'] ?>"
                       data-label="<?= htmlspecialchars($v['brand'].' '.$v['model'], ENT_QUOTES) ?>"
                       data-sub="<?= htmlspecialchars($v['plate_number'], ENT_QUOTES) ?>"
                       onclick="selectAprVehicle(this)">
                    <div class="cs-opt-icon vehicle-ico"><i class="bi bi-truck-front"></i></div>
                    <div style="flex:1;min-width:0">
                      <div class="cs-opt-label"><?= htmlspecialchars($v['brand'].' '.$v['model']) ?></div>
                      <div class="cs-opt-sub"><?= htmlspecialchars($v['plate_number']) ?> · <?= htmlspecialchars($v['vehicle_type']??'') ?></div>
                    </div>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
            <div id="apr_veh_msg" class="mt-1"></div>
          </div>
        </div>

        <!-- Conflict alert -->
        <div class="conflict-alert mt-3" id="apr_conflict">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <span id="apr_conflict_msg"></span>
        </div>

        <!-- Auto-fill notice -->
        <div id="apr_autofill_notice" style="display:none;margin-top:10px;background:#e8f4ff;border:1px solid #bfdbfe;border-radius:8px;padding:8px 12px;font-size:.8rem;color:#1d4ed8">
          <i class="bi bi-stars me-1"></i>
          <span id="apr_autofill_msg">Vehicle auto-filled from driver's default assignment.</span>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      <button type="submit" id="apr_save_btn" class="btn btn-success" disabled>
        <i class="bi bi-check-lg me-1"></i>Approve & Assign
      </button>
    </div>
  </form>
</div></div></div>

<!-- CHANGE ASSIGNMENT (Custom Selects) -->
<div class="modal fade" id="changeModal" tabindex="-1">
<div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header mh-maroon">
    <h5 class="modal-title"><i class="bi bi-arrow-repeat me-2"></i>Change Driver / Vehicle</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
  </div>
  <form method="POST" action="Schedules.php" id="changeForm">
    <input type="hidden" name="action" value="change_assignment">
    <input type="hidden" name="schedule_id" id="chg_sid">
    <input type="hidden" name="vehicle_id" id="chg_vehicle_id_hidden">
    <input type="hidden" name="driver_id"  id="chg_driver_id_hidden">
    <div class="modal-body">
      <div class="sbox mb-3">
        <div class="sbox-title"><i class="bi bi-info-circle me-1"></i>Schedule Details</div>
        <div class="row g-2">
          <div class="col-md-4"><div style="font-size:.75rem;color:#888">Requestor</div><div class="fw-semibold" id="chg_uname">—</div></div>
          <div class="col-md-4"><div style="font-size:.75rem;color:#888">Date Range</div><div class="fw-semibold" id="chg_dates">—</div></div>
          <div class="col-md-4"><div style="font-size:.75rem;color:#888">Time</div><div class="fw-semibold" id="chg_time">—</div></div>
        </div>
      </div>
      <div class="sbox">
        <div class="sbox-title"><i class="bi bi-truck-front me-1"></i>New Vehicle & Driver</div>
        <p class="text-muted mb-3" style="font-size:.82rem">
          <i class="bi bi-stars me-1 text-warning"></i>
          Selecting a driver auto-fills their default vehicle. Options marked <strong>[BOOKED]</strong> are reserved.
        </p>
        <div class="row g-3">
          <!-- Driver picker -->
          <div class="col-md-6">
            <label class="form-label fw-semibold">Driver</label>
            <div class="custom-select-wrap">
              <div class="custom-select-trigger" id="chg_drv_trigger" tabindex="0" onclick="toggleChgCS('drv')">
                <span class="custom-select-placeholder" id="chg_drv_display">— Select Driver —</span>
              </div>
              <div class="custom-select-dropdown" id="chg_drv_dropdown">
                <div class="cs-search-wrap">
                  <i class="bi bi-search" style="color:#aaa;font-size:.8rem"></i>
                  <input class="cs-search-input" placeholder="Search driver…" oninput="filterChgCS('drv',this.value)">
                </div>
                <div id="chg_drv_options">
                  <?php foreach($allDrivers as $d):
                    $vid = $driverVehicleMap[$d['driver_id']] ?? 0;
                    $ini = strtoupper(implode('', array_map(fn($p)=>$p[0], explode(' ', trim($d['driver_name']??'D')))));
                    $ini = substr($ini,0,2);
                  ?>
                  <div class="cs-option"
                       data-value="<?= $d['driver_id'] ?>"
                       data-label="<?= htmlspecialchars($d['driver_name'], ENT_QUOTES) ?>"
                       data-sub="<?= htmlspecialchars($d['license_number']??'', ENT_QUOTES) ?>"
                       data-default-vehicle="<?= $vid ?>"
                       data-initials="<?= htmlspecialchars($ini, ENT_QUOTES) ?>"
                       onclick="selectChgDriver(this)">
                    <div class="cs-opt-icon driver-ico"><?= htmlspecialchars($ini) ?></div>
                    <div style="flex:1;min-width:0">
                      <div class="cs-opt-label"><?= htmlspecialchars($d['driver_name']) ?></div>
                      <div class="cs-opt-sub"><?= htmlspecialchars($d['license_number']??'No license') ?></div>
                    </div>
                    <?php if($vid > 0): ?>
                    <span class="cs-opt-badge badge-assigned"><i class="bi bi-truck-front me-1"></i>Has vehicle</span>
                    <?php endif; ?>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
            <div id="chg_drv_msg" class="mt-1"></div>
          </div>
          <!-- Vehicle picker -->
          <div class="col-md-6">
            <label class="form-label fw-semibold">Vehicle</label>
            <div class="custom-select-wrap">
              <div class="custom-select-trigger" id="chg_veh_trigger" tabindex="0" onclick="toggleChgCS('veh')">
                <span class="custom-select-placeholder" id="chg_veh_display">— Select Vehicle —</span>
              </div>
              <div class="custom-select-dropdown" id="chg_veh_dropdown">
                <div class="cs-search-wrap">
                  <i class="bi bi-search" style="color:#aaa;font-size:.8rem"></i>
                  <input class="cs-search-input" placeholder="Search vehicle…" oninput="filterChgCS('veh',this.value)">
                </div>
                <div id="chg_veh_options">
                  <?php foreach($allVehicles as $v): ?>
                  <div class="cs-option"
                       data-value="<?= $v['vehicle_id'] ?>"
                       data-label="<?= htmlspecialchars($v['brand'].' '.$v['model'], ENT_QUOTES) ?>"
                       data-sub="<?= htmlspecialchars($v['plate_number'], ENT_QUOTES) ?>"
                       onclick="selectChgVehicle(this)">
                    <div class="cs-opt-icon vehicle-ico"><i class="bi bi-truck-front"></i></div>
                    <div style="flex:1;min-width:0">
                      <div class="cs-opt-label"><?= htmlspecialchars($v['brand'].' '.$v['model']) ?></div>
                      <div class="cs-opt-sub"><?= htmlspecialchars($v['plate_number']) ?></div>
                    </div>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
            <div id="chg_veh_msg" class="mt-1"></div>
          </div>
        </div>
        <div class="conflict-alert mt-3" id="chg_conflict">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <span id="chg_conflict_msg"></span>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      <button type="submit" id="chg_save_btn" class="btn btn-primary" disabled>
        <i class="bi bi-save me-1"></i>Save Changes
      </button>
    </div>
  </form>
</div></div></div>

<?php
/* ══════════════════════════════════════════════════════
   STEP C — Add these CSS rules inside <style> in Schedules.php
══════════════════════════════════════════════════════ */
?>
<style>
/* Custom Select — add to Schedules.php <style> block */
.custom-select-wrap { position:relative; }
.custom-select-trigger {
    width:100%; padding:9px 40px 9px 13px;
    border:1.5px solid #e0d0d0; border-radius:10px;
    background:#fff; font-size:.88rem; color:#333;
    cursor:pointer; display:flex; align-items:center; gap:10px;
    transition:border-color .2s, box-shadow .2s; user-select:none;
    min-height:44px; position:relative;
}
.custom-select-trigger:focus,
.custom-select-trigger.open { border-color:#800000; box-shadow:0 0 0 3px rgba(128,0,0,.1); }
.custom-select-trigger::after {
    content:''; position:absolute; right:13px; top:50%;
    transform:translateY(-50%); border:5px solid transparent;
    border-top:6px solid #888; margin-top:3px; transition:transform .2s;
}
.custom-select-trigger.open::after { transform:translateY(-50%) rotate(180deg); margin-top:-3px; }
.custom-select-placeholder { color:#bbb; font-size:.88rem; }
.custom-select-dropdown {
    position:absolute; top:calc(100% + 5px); left:0; right:0; z-index:600;
    background:#fff; border:1.5px solid #e0d0d0; border-radius:12px;
    box-shadow:0 8px 30px rgba(0,0,0,.13); max-height:250px; overflow-y:auto;
    display:none; padding:6px;
}
.custom-select-dropdown.open { display:block; }
.cs-search-wrap { display:flex; align-items:center; gap:6px; background:#f8f8f8; border-radius:8px; padding:6px 10px; margin-bottom:5px; }
.cs-search-input { flex:1; border:none; background:transparent; font-size:.83rem; outline:none; color:#333; }
.cs-option { display:flex; align-items:center; gap:9px; padding:8px 10px; border-radius:8px; cursor:pointer; transition:background .12s; }
.cs-option:hover { background:#fdf5f5; }
.cs-option.selected { background:#fdf5f5; }
.cs-opt-icon { width:32px; height:32px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:.85rem; flex-shrink:0; }
.cs-opt-icon.driver-ico  { background:#fdf5f5; color:#800000; }
.cs-opt-icon.vehicle-ico { background:#eff6ff; color:#1d4ed8; }
.cs-opt-label { font-weight:600; font-size:.83rem; color:#1a1a1a; }
.cs-opt-sub   { font-size:.7rem; color:#888; }
.cs-opt-badge { font-size:.66rem; font-weight:700; padding:1px 7px; border-radius:10px; margin-left:auto; flex-shrink:0; white-space:nowrap; }
.badge-assigned   { background:#d1e7dd; color:#0f5132; }
.badge-unassigned { background:#fff3cd; color:#856404; }
</style>

<?php
/* ══════════════════════════════════════════════════════
   STEP D — Replace/add this JS block in Schedules.php.
   Find the section "/* ── Approve ──" in the big <script>
   and replace it (and the change assignment handler) with:
══════════════════════════════════════════════════════ */
?>
<script>
/* ── Driver-Vehicle map from PHP ── */
const driverVehicleMap = <?= $driverVehicleMapJson ?>;

/* ══ APPROVE Custom Selects ══ */
let aprCS = { drv: null, veh: null };

function toggleAprCS(which){
    const drop = document.getElementById('apr_'+which+'_dropdown');
    const trig = document.getElementById('apr_'+which+'_trigger');
    const isOpen = drop.classList.contains('open');
    closeAllAprCS();
    if(!isOpen){ drop.classList.add('open'); trig.classList.add('open'); }
}
function closeAllAprCS(){
    ['drv','veh'].forEach(w => {
        document.getElementById('apr_'+w+'_dropdown')?.classList.remove('open');
        document.getElementById('apr_'+w+'_trigger')?.classList.remove('open');
    });
}
function filterAprCS(which, q){
    document.querySelectorAll('#apr_'+which+'_options .cs-option').forEach(o => {
        o.style.display = (!q || (o.dataset.label+' '+o.dataset.sub).toLowerCase().includes(q.toLowerCase())) ? '' : 'none';
    });
}

function selectAprDriver(el){
    const vid = el.dataset.value;
    const label = el.dataset.label;
    const ini   = el.dataset.initials || label[0].toUpperCase();
    aprCS.drv = vid;
    document.getElementById('apr_driver_id_hidden').value = vid;
    // Update trigger
    document.getElementById('apr_drv_trigger').innerHTML =
        `<div style="display:flex;align-items:center;gap:9px;flex:1">
           <div style="width:30px;height:30px;border-radius:8px;background:#fdf5f5;color:#800000;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.8rem;flex-shrink:0">${ini}</div>
           <div style="min-width:0">
             <div style="font-weight:600;font-size:.88rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${label}</div>
             <div style="font-size:.7rem;color:#888">${el.dataset.sub||'Driver'}</div>
           </div>
         </div>`;
    document.getElementById('apr_drv_trigger').classList.remove('open');
    document.getElementById('apr_drv_dropdown').classList.remove('open');
    document.querySelectorAll('#apr_drv_options .cs-option').forEach(o => o.classList.remove('selected'));
    el.classList.add('selected');

    // ── Auto-fill vehicle from assignment ──
    const defaultVid = parseInt(el.dataset.defaultVehicle || 0);
    if(defaultVid > 0){
        const vEl = document.querySelector(`#apr_veh_options .cs-option[data-value="${defaultVid}"]`);
        if(vEl && !vEl.classList.contains('disabled')){
            selectAprVehicle(vEl);
            document.getElementById('apr_autofill_notice').style.display = '';
        }
    }
    // check driver availability badge
    const lvl = worstDriverConflict(vid, aprDS, aprDE, aprTS, aprTE, document.getElementById('apr_sid').value);
    const msg = document.getElementById('apr_drv_msg');
    msg.innerHTML = lvl ? driverAvailBadge(vid, aprDS, aprDE, aprTS, aprTE, document.getElementById('apr_sid').value) : '<span class="avail-badge avail-ok"><i class="bi bi-check-circle me-1"></i>Available</span>';
    checkAprConflict();
    updateAprSaveBtn();
}

function selectAprVehicle(el){
    const vid = el.dataset.value;
    aprCS.veh = vid;
    document.getElementById('apr_vehicle_id_hidden').value = vid;
    document.getElementById('apr_veh_trigger').innerHTML =
        `<div style="display:flex;align-items:center;gap:9px;flex:1">
           <div style="width:30px;height:30px;border-radius:8px;background:#eff6ff;color:#1d4ed8;display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0"><i class="bi bi-truck-front"></i></div>
           <div style="min-width:0">
             <div style="font-weight:600;font-size:.88rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${el.dataset.label}</div>
             <div style="font-size:.7rem;color:#888">${el.dataset.sub}</div>
           </div>
         </div>`;
    document.getElementById('apr_veh_trigger').classList.remove('open');
    document.getElementById('apr_veh_dropdown').classList.remove('open');
    document.querySelectorAll('#apr_veh_options .cs-option').forEach(o => o.classList.remove('selected'));
    el.classList.add('selected');
    const msg = document.getElementById('apr_veh_msg');
    const lvl = worstVehicleConflict(vid, aprDS, aprDE, aprTS, aprTE, document.getElementById('apr_sid').value);
    msg.innerHTML = lvl ? vehicleAvailBadge(vid, aprDS, aprDE, aprTS, aprTE, document.getElementById('apr_sid').value) : '<span class="avail-badge avail-ok"><i class="bi bi-check-circle me-1"></i>Available</span>';
    checkAprConflict();
    updateAprSaveBtn();
}
function checkAprConflict(){
    const sid = document.getElementById('apr_sid').value;
    const vid = document.getElementById('apr_vehicle_id_hidden').value;
    const did = document.getElementById('apr_driver_id_hidden').value;
    const alertEl = document.getElementById('apr_conflict'), msgEl = document.getElementById('apr_conflict_msg');
    const btn = document.getElementById('apr_save_btn');
    let msg = null;
    if(vid){ const lvl=worstVehicleConflict(vid,aprDS,aprDE,aprTS,aprTE,sid); if(lvl) msg=lvl==='ontrip'?'Selected vehicle is currently on trip.':'Selected vehicle is already booked on these dates.'; }
    if(!msg&&did){ const lvl=worstDriverConflict(did,aprDS,aprDE,aprTS,aprTE,sid); if(lvl) msg=lvl==='ontrip'?'Selected driver is currently on trip.':'Selected driver is already booked on these dates.'; }
    if(msg){ msgEl.textContent=msg; alertEl.classList.add('show'); btn.disabled=true; }
    else   { alertEl.classList.remove('show'); if(vid&&did) btn.disabled=false; }
}
function updateAprSaveBtn(){
    const vid = document.getElementById('apr_vehicle_id_hidden').value;
    const did = document.getElementById('apr_driver_id_hidden').value;
    const conf = document.getElementById('apr_conflict').classList.contains('show');
    document.getElementById('apr_save_btn').disabled = !(vid && did && !conf);
}

/* ── Mark available/booked in dropdown options ── */
function markAprOptions(){
    const sid = document.getElementById('apr_sid').value;
    document.querySelectorAll('#apr_drv_options .cs-option').forEach(o => {
        const lvl = worstDriverConflict(o.dataset.value, aprDS, aprDE, aprTS, aprTE, sid);
        const badge = o.querySelector('.cs-opt-badge');
        if(lvl){
            o.style.opacity = '.5';
            if(badge) badge.textContent = lvl==='ontrip'?'ON TRIP':'BOOKED';
            if(badge) badge.className = 'cs-opt-badge badge-busy';
        }
    });
    document.querySelectorAll('#apr_veh_options .cs-option').forEach(o => {
        const lvl = worstVehicleConflict(o.dataset.value, aprDS, aprDE, aprTS, aprTE, sid);
        const badge = o.querySelector('.cs-opt-badge');
        if(lvl){
            o.style.opacity = '.5';
            if(badge){ badge.textContent = lvl==='ontrip'?'ON TRIP':'BOOKED'; badge.className='cs-opt-badge badge-busy'; }
        } else {
            o.style.opacity = '';
            if(badge && badge.classList.contains('badge-busy')){ badge.textContent='Free'; badge.className='cs-opt-badge badge-unassigned'; }
        }
    });
}

/* ── Approve button click ── */
document.addEventListener('click', e => {
    const b = e.target.closest('.btn-approve'); if(!b) return;
    const d = b.dataset;
    aprDS=d.ds; aprDE=d.de; aprTS=d.ts; aprTE=d.te;
    aprCS = { drv:null, veh:null };
    document.getElementById('apr_sid').value = d.id;
    document.getElementById('apr_vehicle_id_hidden').value = '';
    document.getElementById('apr_driver_id_hidden').value  = '';
    document.getElementById('apr_uname').textContent = d.username;
    document.getElementById('apr_dates').textContent = d.ds===d.de ? d.ds : d.ds+' → '+d.de;
    document.getElementById('apr_time').textContent  = (d.ts||'--')+' – '+(d.te||'--');
    document.getElementById('apr_pax').textContent   = d.pax ? d.pax+' passenger'+(parseInt(d.pax)>1?'s':'') : '—';
    // Reset triggers
    document.getElementById('apr_drv_trigger').innerHTML = '<span class="custom-select-placeholder" id="apr_drv_display">— Select Driver —</span>';
    document.getElementById('apr_veh_trigger').innerHTML = '<span class="custom-select-placeholder" id="apr_veh_display">— Select Vehicle —</span>';
    document.getElementById('apr_drv_msg').innerHTML = '';
    document.getElementById('apr_veh_msg').innerHTML = '';
    document.getElementById('apr_conflict').classList.remove('show');
    document.getElementById('apr_autofill_notice').style.display = 'none';
    document.getElementById('apr_save_btn').disabled = true;
    document.querySelectorAll('#apr_drv_options .cs-option, #apr_veh_options .cs-option').forEach(o => { o.classList.remove('selected'); o.style.opacity=''; });
    markAprOptions();
    new bootstrap.Modal(document.getElementById('approveModal')).show();
});

/* ══ CHANGE ASSIGNMENT Custom Selects ══ */
function toggleChgCS(which){
    const drop = document.getElementById('chg_'+which+'_dropdown');
    const trig = document.getElementById('chg_'+which+'_trigger');
    const isOpen = drop.classList.contains('open');
    ['drv','veh'].forEach(w=>{ document.getElementById('chg_'+w+'_dropdown')?.classList.remove('open'); document.getElementById('chg_'+w+'_trigger')?.classList.remove('open'); });
    if(!isOpen){ drop.classList.add('open'); trig.classList.add('open'); }
}
function filterChgCS(which, q){
    document.querySelectorAll('#chg_'+which+'_options .cs-option').forEach(o => {
        o.style.display = (!q || (o.dataset.label+' '+o.dataset.sub).toLowerCase().includes(q.toLowerCase())) ? '' : 'none';
    });
}
function selectChgDriver(el){
    const vid = el.dataset.value;
    const label = el.dataset.label;
    const ini = el.dataset.initials || label[0].toUpperCase();
    document.getElementById('chg_driver_id_hidden').value = vid;
    document.getElementById('chg_drv_trigger').innerHTML =
        `<div style="display:flex;align-items:center;gap:9px;flex:1">
           <div style="width:30px;height:30px;border-radius:8px;background:#fdf5f5;color:#800000;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.8rem;flex-shrink:0">${ini}</div>
           <div><div style="font-weight:600;font-size:.88rem">${label}</div><div style="font-size:.7rem;color:#888">${el.dataset.sub||''}</div></div></div>`;
    document.getElementById('chg_drv_trigger').classList.remove('open');
    document.getElementById('chg_drv_dropdown').classList.remove('open');
    document.querySelectorAll('#chg_drv_options .cs-option').forEach(o => o.classList.remove('selected'));
    el.classList.add('selected');
    // Auto-fill vehicle
    const defaultVid = parseInt(el.dataset.defaultVehicle || 0);
    if(defaultVid > 0){
        const vEl = document.querySelector(`#chg_veh_options .cs-option[data-value="${defaultVid}"]`);
        if(vEl) selectChgVehicle(vEl);
    }
    const sid = document.getElementById('chg_sid').value;
    const lvl = worstDriverConflict(vid,chgDS,chgDE,chgTS,chgTE,sid);
    document.getElementById('chg_drv_msg').innerHTML = lvl ? driverAvailBadge(vid,chgDS,chgDE,chgTS,chgTE,sid) : '<span class="avail-badge avail-ok"><i class="bi bi-check-circle me-1"></i>Available</span>';
    checkChgConflict(); updateChgSaveBtn();
}
function selectChgVehicle(el){
    const vid = el.dataset.value;
    document.getElementById('chg_vehicle_id_hidden').value = vid;
    document.getElementById('chg_veh_trigger').innerHTML =
        `<div style="display:flex;align-items:center;gap:9px;flex:1">
           <div style="width:30px;height:30px;border-radius:8px;background:#eff6ff;color:#1d4ed8;display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0"><i class="bi bi-truck-front"></i></div>
           <div><div style="font-weight:600;font-size:.88rem">${el.dataset.label}</div><div style="font-size:.7rem;color:#888">${el.dataset.sub}</div></div></div>`;
    document.getElementById('chg_veh_trigger').classList.remove('open');
    document.getElementById('chg_veh_dropdown').classList.remove('open');
    document.querySelectorAll('#chg_veh_options .cs-option').forEach(o => o.classList.remove('selected'));
    el.classList.add('selected');
    const sid = document.getElementById('chg_sid').value;
    const lvl = worstVehicleConflict(vid,chgDS,chgDE,chgTS,chgTE,sid);
    document.getElementById('chg_veh_msg').innerHTML = lvl ? vehicleAvailBadge(vid,chgDS,chgDE,chgTS,chgTE,sid) : '<span class="avail-badge avail-ok"><i class="bi bi-check-circle me-1"></i>Available</span>';
    checkChgConflict(); updateChgSaveBtn();
}
function checkChgConflict(){
    const sid = document.getElementById('chg_sid').value;
    const vid = document.getElementById('chg_vehicle_id_hidden').value;
    const did = document.getElementById('chg_driver_id_hidden').value;
    const alertEl = document.getElementById('chg_conflict'), msgEl = document.getElementById('chg_conflict_msg');
    let msg = null;
    if(vid){ const lvl=worstVehicleConflict(vid,chgDS,chgDE,chgTS,chgTE,sid); if(lvl) msg=lvl==='ontrip'?'Vehicle is on trip.':'Vehicle already booked.'; }
    if(!msg&&did){ const lvl=worstDriverConflict(did,chgDS,chgDE,chgTS,chgTE,sid); if(lvl) msg=lvl==='ontrip'?'Driver is on trip.':'Driver already booked.'; }
    if(msg){ msgEl.textContent=msg; alertEl.classList.add('show'); document.getElementById('chg_save_btn').disabled=true; }
    else   { alertEl.classList.remove('show'); }
}
function updateChgSaveBtn(){
    const vid = document.getElementById('chg_vehicle_id_hidden').value;
    const did = document.getElementById('chg_driver_id_hidden').value;
    const conf = document.getElementById('chg_conflict').classList.contains('show');
    document.getElementById('chg_save_btn').disabled = !(vid && did && !conf);
}

/* ── Change Assignment click ── */
document.addEventListener('click', e=>{
    const b = e.target.closest('.btn-change'); if(!b) return;
    const d = b.dataset; chgId=d.id; chgDS=d.ds; chgDE=d.de; chgTS=d.ts; chgTE=d.te;
    document.getElementById('chg_sid').value = d.id;
    document.getElementById('chg_vehicle_id_hidden').value = '';
    document.getElementById('chg_driver_id_hidden').value  = '';
    document.getElementById('chg_uname').textContent = d.username;
    document.getElementById('chg_dates').textContent = d.ds===d.de ? d.ds : d.ds+' → '+d.de;
    document.getElementById('chg_time').textContent  = (d.ts||'--')+' – '+(d.te||'--');
    document.getElementById('chg_drv_trigger').innerHTML = '<span class="custom-select-placeholder">— Select Driver —</span>';
    document.getElementById('chg_veh_trigger').innerHTML = '<span class="custom-select-placeholder">— Select Vehicle —</span>';
    document.getElementById('chg_drv_msg').innerHTML = '';
    document.getElementById('chg_veh_msg').innerHTML = '';
    document.getElementById('chg_conflict').classList.remove('show');
    document.getElementById('chg_save_btn').disabled = true;
    document.querySelectorAll('#chg_drv_options .cs-option, #chg_veh_options .cs-option').forEach(o => { o.classList.remove('selected'); o.style.opacity=''; });
    // pre-select if existing assignment
    if(d.did && d.did!=='0'){
        const dEl = document.querySelector(`#chg_drv_options .cs-option[data-value="${d.did}"]`);
        if(dEl) selectChgDriver(dEl);
    }
    if(d.vid && d.vid!=='0'){
        const vEl = document.querySelector(`#chg_veh_options .cs-option[data-value="${d.vid}"]`);
        if(vEl) selectChgVehicle(vEl);
    }
    new bootstrap.Modal(document.getElementById('changeModal')).show();
});

/* Close dropdowns on outside click */
document.addEventListener('click', e => {
    if(!e.target.closest('.custom-select-wrap')){
        closeAllAprCS();
        ['drv','veh'].forEach(w=>{ document.getElementById('chg_'+w+'_dropdown')?.classList.remove('open'); document.getElementById('chg_'+w+'_trigger')?.classList.remove('open'); });
    }
});

/* NOTE: Keep all the existing conflict detection functions (worstVehicleConflict,
   worstDriverConflict, vehicleAvailBadge, driverAvailBadge, rangesOverlap, etc.)
   — they are unchanged and still required. */
</script>