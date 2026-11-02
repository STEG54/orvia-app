// Relevés d'heures mensuels : construits depuis le planning, vérifiés par la direction, envoyés pour signature dans l'appli de l'animatrice.
const RL = { mois:new Date().toISOString().slice(0, 7), mode:"mois", du:"", au:"", qui:0, list:[], hist:[], txt:"", ouvert:false, aid:0, R:null };
const rKey = () => RL.mode === "periode" && RL.du && RL.au && RL.du <= RL.au ? RL.du + "_" + RL.au : RL.mois;
const rDur = m => Math.floor(m / 60) + "h" + (m % 60 ? String(m % 60).padStart(2, "0") : "");
const rHm = s => { const m = /^(\d{1,2}):(\d{2})$/.exec(s || ""); return m ? +m[1] * 60 + +m[2] : 0; };
const rNom = a => a.nom.toUpperCase() + " " + a.prenom.charAt(0).toUpperCase() + a.prenom.slice(1).toLowerCase();
const rEtat = R => R.statut === "signe" ? `<span class="pill p-ok">signé</span>${R.signe_test ? ` <span class="pill">test</span>` : ""}` : R.statut === "envoye" ? `<span class="pill p-warn">envoyé, à signer</span>` : `<span class="pill">à vérifier</span>`;
async function loadReleves(){
  const r = await papi("releves", null, "mois=" + rKey() + (RL.qui ? "&animatrice_id=" + RL.qui : "")); RL.list = r.releves; RL.hist = r.historique || []; RL.txt = r.mois_txt; RL.ouvert = r.ouvert;
  RL.R = RL.aid ? (await papi("releve", null, "animatrice_id=" + RL.aid + "&mois=" + (RL.open || rKey()))).releve : null;
}
function vReleves(){
  const test = RL.ouvert ? "" : `<div class="plan-test"><b>Mode test.</b> Les intervenants ne voient pas encore leurs relevés et les e-mails vous arrivent à vous, sauf les testeurs internes (testeurs) qui les voient et les signent dans leur propre appli. Pour signer à leur place en test : onglet Intervenants, « Voir son appli », puis « Mes relevés d'heures ». L'ouverture se fait dans Planning.</div>`;
  if (RL.R) return vReleve(test);
  const L = RL.list, tot = L.reduce((a, R) => a + R.minutes, 0);
  return `<div class="card accent"><h2>Relevés d'heures</h2><div class="muted">Le relevé se remplit tout seul avec les attributions du planning. L'administration vérifie, corrige si besoin, puis l'envoie : l'intervenant(e) le signe dans son appli.</div>${test}
      <div class="row3"><label>Période<select id="rl-mode"><option value="mois">Un mois</option><option value="periode" ${RL.mode === "periode" ? "selected" : ""}>Période libre (du… au…)</option></select></label>
        ${RL.mode === "periode" ? `<label>Du<input type="date" id="rl-du" value="${RL.du}"></label><label>Au<input type="date" id="rl-au" value="${RL.au}"></label>` : `<label>Mois<input type="month" id="rl-m" value="${RL.mois}"></label><span></span>`}</div>
      <label style="max-width:360px">Personne<select id="rl-qui"><option value="">Toutes les personnes de la période</option>${[...A.L.animatrices].filter(a => +a.actif).sort((x, y) => (x.nom + x.prenom).localeCompare(y.nom + y.prenom)).map(a => `<option value="${a.id}" ${+RL.qui === +a.id ? "selected" : ""}>${esc(rNom(a))}</option>`).join("")}</select></label></div>
    <div class="card"><div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><h2>${esc(RL.txt)} · ${L.length} personne${L.length > 1 ? "s" : ""} · ${rDur(tot)}</h2>
      ${L.filter(R => R.statut === "brouillon" && R.minutes > 0).length ? `<button class="primary" id="rl-all">Envoyer les ${L.filter(R => R.statut === "brouillon" && R.minutes > 0).length} relevés à vérifier</button>` : ""}</div>
      ${L.length ? `<div class="tablewrap"><table><tr><th>Intervenant(e)</th><th>Zone</th><th class="num">Jours</th><th class="num">Heures</th><th>État</th></tr>${L.map(R => `<tr class="click" data-rla="${R.animatrice.id}">
        <td><b>${esc(rNom(R.animatrice))}</b>${R.change ? `<div class="hint" style="color:var(--terra)">Le planning a changé depuis l'envoi</div>` : ""}</td><td>${esc(R.animatrice.zone)}</td><td class="num">${R.jours}</td><td class="num"><b>${rDur(R.minutes)}</b></td><td>${rEtat(R)}</td></tr>`).join("")}</table></div>`
      : `<div class="empty">Aucune animation attribuée sur cette période. Les relevés apparaissent dès qu'une date est attribuée dans Planning.</div>`}</div>
    ${RL.qui ? `<div class="card"><h2>Tous les relevés de cette personne</h2>${RL.hist.length ? `<div class="tablewrap"><table><tr><th>Période</th><th class="num">Jours</th><th class="num">Heures</th><th>État</th></tr>${RL.hist.map(R => `<tr class="click" data-rlh="${R.mois}"><td>${esc(R.mois_txt)}</td><td class="num">${R.jours}</td><td class="num"><b>${rDur(R.minutes)}</b></td><td>${rEtat(R)}</td></tr>`).join("")}</table></div>` : `<div class="empty">Aucun relevé enregistré pour l'instant.</div>`}</div>` : ""}`;
}
function vReleve(test){
  const R = RL.R, ed = R.statut === "brouillon";
  const inp = (v, k, w) => `<input class="rl-in" data-k="${k}" value="${esc(v)}" ${w || ""}>`;
  const rows = R.lignes.map(l => ed ? `<tr data-rl="${l.src}|${esc(l.id)}" class="${l.exclu ? "plan-an" : ""}">
      <td>${l.src === "x" ? `<input type="date" class="rl-in" style="width:140px" data-k="date" value="${l.date}">` : `<b>${esc(gDay(l.date))}</b>`}</td>
      <td>${l.src === "x" ? `<input class="rl-note" data-k="magasin" placeholder="Point d'intervention" value="${esc(l.magasin)}">` : esc(l.magasin || "Point d'intervention à préciser")}<div class="hint">${esc(l.poste)}${l.devis ? " · devis " + esc(l.devis) : ""}${l.modifie ? " · <b>horaires corrigés</b>" : ""}</div></td>
      <td>${inp(l.debut, "debut", 'type="time"')}</td><td>${inp(l.fin, "fin", 'type="time"')}</td><td><input class="rl-in" style="width:64px" type="number" min="0" step="15" data-k="pause" value="${+l.pause}"></td>
      <td class="num"><b data-min>${rDur(l.minutes)}</b></td><td><input class="rl-note" data-k="note" placeholder="Note" value="${esc(l.note || "")}"></td>
      <td>${l.src === "x" ? `<button class="ghost sm" data-rldel>✕</button>` : `<label class="ck" title="Ne pas compter"><input type="checkbox" data-k="exclu" ${l.exclu ? "checked" : ""}><span>Exclure</span></label>`}</td></tr>`
    : `<tr class="${l.exclu ? "plan-an" : ""}"><td><b>${esc(gDay(l.date))}</b></td><td>${esc(l.magasin || "Point d'intervention à préciser")}<div class="hint">${esc(l.poste)}</div></td><td>${esc(l.debut)}</td><td>${esc(l.fin)}</td><td>${+l.pause ? rDur(+l.pause) : ""}</td><td class="num"><b>${rDur(l.minutes)}</b></td><td>${esc(l.note || "")}${l.exclu ? " · non compté" : ""}</td><td></td></tr>`).join("");
  const btns = ed ? `<button class="ghost" id="rl-add">Ajouter une ligne hors planning</button><button class="ghost" id="rl-save">Enregistrer</button><button class="ghost" id="rl-pdf">Voir le PDF</button><button class="primary" id="rl-send" ${R.lignes.length ? "" : "disabled"}>Vérifié, envoyer pour signature</button>`
    : R.statut === "envoye" ? `<button class="ghost" id="rl-pdf">Voir le PDF</button><button class="ghost" id="rl-resend">Renvoyer l'e-mail</button><button class="ghost" id="rl-open">Repasser en brouillon</button>`
    : `<button class="primary" id="rl-pdf">PDF signé</button><button class="ghost" id="rl-open">Rouvrir (annule la signature)</button>`;
  return `<div class="card accent"><div class="btns" style="margin:0"><button class="ghost" id="rl-back">‹ Tous les relevés</button></div>
      <h2>Relevé d'heures · ${esc(rNom(R.animatrice))} · ${esc(R.mois_txt)}</h2>${test}
      <div style="display:flex;gap:18px;align-items:baseline;flex-wrap:wrap"><span style="font-size:28px;font-weight:600" id="rl-tot">${rDur(R.minutes)}</span><span class="muted">${R.jours} jour${R.jours > 1 ? "s" : ""}</span>${rEtat(R)}</div>
      ${R.change ? `<div class="plan-test">Le planning a changé depuis l'envoi. Repassez le relevé en brouillon pour le mettre à jour, puis renvoyez-le.</div>` : ""}
      ${R.statut === "signe" ? `<div class="hint">Signé par <b>${esc(R.signe_nom)}</b> le ${esc(R.signe_le.slice(0, 16).replace(/^(\d+)-(\d+)-(\d+)/, "$3/$2/$1"))} · IP ${esc(R.signe_ip)} · empreinte ${esc(R.empreinte.slice(0, 16))}…</div>`
        : R.envoye_le ? `<div class="hint">Envoyé par ${esc(R.envoye_par)} le ${esc(R.envoye_le.slice(0, 16).replace(/^(\d+)-(\d+)-(\d+)/, "$3/$2/$1"))}</div>` : `<div class="hint">En brouillon : les nouvelles attributions du planning s'ajoutent automatiquement. Corrigez les horaires si besoin.</div>`}</div>
    <div class="card"><div class="tablewrap"><table class="plan-t"><tr><th>Date</th><th>Point d'intervention</th><th>Début</th><th>Fin</th><th>Pause (min)</th><th class="num">Heures</th><th>Note</th><th></th></tr>${rows || `<tr><td colspan="8" class="empty">Aucune ligne.</td></tr>`}</table></div>
      <label>Commentaire (visible sur le relevé)<textarea id="rl-com" rows="2" ${ed ? "" : "disabled"}>${esc(R.commentaire || "")}</textarea></label>
      <div class="btns">${btns}</div></div>
    ${R.journal.length ? `<div class="card"><h2>Historique</h2><div class="plan-log">${R.journal.map(j => `<div><span class="hint">${esc(j.le.slice(0, 16).replace(/^(\d+)-(\d+)-(\d+)/, "$3/$2/$1"))}</span> ${esc(j.texte)}</div>`).join("")}</div></div>` : ""}`;
}
function rCollect(){
  const ajust = {}, extras = [];
  document.querySelectorAll("[data-rl]").forEach(tr => { const [src, ...id] = tr.dataset.rl.split("|"), v = {};
    tr.querySelectorAll("[data-k]").forEach(i => v[i.dataset.k] = i.type === "checkbox" ? i.checked : i.value);
    if (src === "m") ajust[id.join("|")] = v; else extras.push(v); });
  return {animatrice_id:RL.aid, mois:RL.R.mois, ajust, extras, commentaire:$("#rl-com").value};
}
function bReleves(){
  const re = () => go("gestion");
  if (!RL.R) {
    if ($("#rl-m")) $("#rl-m").onchange = e => { RL.mois = e.target.value || RL.mois; re(); };
    $("#rl-mode").onchange = e => { RL.mode = e.target.value; if (RL.mode === "periode" && !RL.du) { RL.du = RL.mois + "-01"; const d = new Date(RL.mois + "-15T12:00:00"); d.setMonth(d.getMonth() + 1, 0); RL.au = d.toISOString().slice(0, 10); } re(); };
    if ($("#rl-du")) { $("#rl-du").onchange = e => { RL.du = e.target.value; if (RL.au && RL.du <= RL.au) re(); }; $("#rl-au").onchange = e => { RL.au = e.target.value; if (RL.du && RL.du <= RL.au) re(); }; }
    $("#rl-qui").onchange = e => { RL.qui = +e.target.value || 0; re(); };
    document.querySelectorAll("[data-rlh]").forEach(tr => tr.onclick = () => { RL.aid = RL.qui; RL.open = tr.dataset.rlh; re(); });
    if ($("#rl-all")) $("#rl-all").onclick = async () => { const b = $("#rl-all"); if (b.dataset.confirm !== "1") { b.dataset.confirm = "1"; b.textContent = "Confirmer : tous vérifiés, envoyer pour signature"; return; }
      b.disabled = true; try { const r = await papi("releves_envoyer_tous", {mois:rKey(), ids:RL.list.filter(R => R.statut === "brouillon" && R.minutes > 0).map(R => +R.animatrice.id)});
        toast(r.n + " relevé" + (r.n > 1 ? "s envoyés" : " envoyé") + " pour signature" + (r.test ? " (mode test : e-mails à vous)" : "") + (r.n - r.mails > 0 ? " · " + (r.n - r.mails) + " sans e-mail" : "")); re(); } catch(e) { toast(e.message); b.disabled = false; } };
    document.querySelectorAll("[data-rla]").forEach(tr => tr.onclick = () => { RL.aid = +tr.dataset.rla; RL.open = null; re(); }); return;
  }
  $("#rl-back").onclick = () => { RL.aid = 0; RL.R = null; RL.open = null; re(); };
  const q = "animatrice_id=" + RL.aid + "&mois=" + RL.R.mois;
  const save = async () => { RL.R = (await papi("releve_save", rCollect())).releve; };
  // Total recalculé à la saisie.
  const recalc = () => { let t = 0; document.querySelectorAll("[data-rl]").forEach(tr => { const g = k => tr.querySelector(`[data-k="${k}"]`); const m = Math.max(0, rHm(g("fin").value) - rHm(g("debut").value) - (+g("pause").value || 0));
    tr.querySelector("[data-min]").textContent = rDur(m); const ex = g("exclu"); if (!ex || !ex.checked) t += m; }); $("#rl-tot").textContent = rDur(t); };
  document.querySelectorAll("[data-rl] input").forEach(i => i.oninput = i.onchange = recalc);
  document.querySelectorAll("[data-rldel]").forEach(b => b.onclick = () => { b.closest("tr").remove(); recalc(); });
  if ($("#rl-add")) $("#rl-add").onclick = async () => { const d = rCollect(); d.extras.push({date:RL.R.mois.slice(0, 10).length === 7 ? RL.R.mois + "-01" : RL.R.mois.slice(0, 10), magasin:"", poste:"Hors planning", debut:"10:00", fin:"18:00", pause:60, note:""});
    try { RL.R = (await papi("releve_save", d)).releve; re(); } catch(e) { toast(e.message); } };
  if ($("#rl-save")) $("#rl-save").onclick = async () => { try { await save(); toast("Relevé enregistré"); re(); } catch(e) { toast(e.message); } };
  $("#rl-pdf").onclick = async () => { try { if (RL.R.statut === "brouillon") await save(); window.open("planning_api.php?action=releve_pdf&" + q, "_blank"); } catch(e) { toast(e.message); } };
  if ($("#rl-send")) $("#rl-send").onclick = async e => { const b = e.target; if (b.dataset.confirm !== "1") { b.dataset.confirm = "1"; b.textContent = "Confirmer l'envoi à " + RL.R.animatrice.prenom; return; }
    try { await save(); const r = await papi("releve_envoyer", {animatrice_id:RL.aid, mois:RL.R.mois}); RL.R = r.releve; toast(r.mail ? "Relevé envoyé pour signature" + (r.test ? " (e-mail à vous, mode test)" : "") : "Relevé figé, mais e-mail non parti (pas d'adresse ?)"); re(); } catch(x) { toast(x.message); } };
  if ($("#rl-resend")) $("#rl-resend").onclick = async () => { try { const r = await papi("releve_envoyer", {animatrice_id:RL.aid, mois:RL.R.mois}); toast(r.mail ? "E-mail renvoyé" + (r.test ? " (à vous, mode test)" : "") : "E-mail non parti"); re(); } catch(e) { toast(e.message); } };
  if ($("#rl-open")) $("#rl-open").onclick = async e => { const b = e.target; if (b.dataset.confirm !== "1") { b.dataset.confirm = "1"; b.textContent = RL.R.statut === "signe" ? "Confirmer : la signature sera annulée" : "Confirmer"; return; }
    try { RL.R = (await papi("releve_rouvrir", {animatrice_id:RL.aid, mois:RL.R.mois})).releve; toast("Relevé repassé en brouillon"); re(); } catch(x) { toast(x.message); } };
}
