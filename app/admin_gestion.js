// Administration (la direction) : devis, factures, clients, réglages.
const G = { sub:"cz_jour", tab:null, docs:null, ini:null, ed:null, cal:{}, cli:null, mail:null };
async function gapi(action, body, query){
  const url = "gestion_api.php?action=" + action + (query ? "&" + query : "");
  const r = await fetch(url, body ? {method:"POST", credentials:"same-origin", headers:{"Content-Type":"application/json","X-Requested-With":"amaya"}, body:JSON.stringify(body)} : {credentials:"same-origin"});
  if (typeof sessionExpiree === "function") sessionExpiree(r);
  const t = await r.text(); let d = {}; try { d = JSON.parse(t); } catch(e) {}
  if (!r.ok) { const e = new Error(d.error || "Erreur " + r.status); e.status = r.status; throw e; } return d;
}
const gEur = v => (+v || 0).toLocaleString("fr-FR", {minimumFractionDigits:2, maximumFractionDigits:2}) + " €";
const gNum = v => +(String(v ?? "").replace(/\s/g, "").replace(",", ".")) || 0;
const gIso = d => { const x = new Date(d); x.setMinutes(x.getMinutes() - x.getTimezoneOffset()); return x.toISOString().slice(0, 10); };
const gToday = () => gIso(new Date());
const gAdd = (iso, n) => { const d = new Date(iso + "T12:00:00"); d.setDate(d.getDate() + n); return gIso(d); };
const JOURS = ["lun", "mar", "mer", "jeu", "ven", "sam", "dim"];
const MOIS = ["janvier","février","mars","avril","mai","juin","juillet","août","septembre","octobre","novembre","décembre"];
// Postes proposés selon le pôle du devis (Réglages > Pôles et postes).
const POLES_ACTIFS = () => { const a = String((G.ini && G.ini.reglages.poles) || "anim").split(",").map(s => s.trim()).filter(p => (G.ini.poles || {})[p]); return a.length ? a : ["anim"]; };
const postesDe = p => String((G.ini && G.ini.reglages["postes_" + p]) || "").split("\n").map(s => s.trim()).filter(Boolean);
const ROLES_P = () => { const L = postesDe((G.ed && G.ed.pole) || POLES_ACTIFS()[0]); return L.length ? L : ["Animateur(trice) commercial(e)"]; };
const gDay = iso => { const d = new Date(iso + "T12:00:00"); return JOURS[(d.getDay() + 6) % 7] + " " + iso.slice(8, 10) + "/" + iso.slice(5, 7); };
const ferie = (zone, iso) => ((G.ini.feries || {})[zone] || {})[iso] || "";

async function loadGestion(){
  if (!G.ini) G.ini = await gapi("init");
  if (G.sub.startsWith("cz_")) return czLoad();
  if (G.sub === "tableau" && !G.ed) [G.tab, G.pil] = await Promise.all([gapi("tableau"), gapi("pilotage")]);
  if (G.sub === "planning" && !G.ed) await loadPlanning();
  if (G.sub === "releves" && !G.ed) await loadReleves();
  if (G.sub === "contrats" && !G.ed) await loadContrats();
  if (G.sub === "paye" && !G.ed) await loadPaye();
  if (G.sub === "marges" && !G.ed) await loadMarges();
  if (G.sub === "operations" && !G.ed) await loadOps();
  if (G.sub === "poste" && !G.ed) await loadPoste();
  if (G.sub === "rup" && !G.ed) await loadRup();
  if (G.sub === "maintenance" && !G.ed) await loadMaint();
  if (G.sub === "ndf" && !G.ed) await loadNdf();
  if ((G.sub === "devis" || G.sub === "factures") && !G.ed) G.docs = (await gapi("docs", null, "type=" + (G.sub === "factures" ? "facture" : "devis"))).docs;
}
function vGestion(){
  const subs = [["tableau","Tableau de bord"],["devis","Devis"],["planning","Planning"],["contrats","Contrats"],["releves","Relevés d'heures"],["paye","Paye"],["factures","Factures"],["clients","Clients"],["reglages","Réglages"]];
  const head = "";
  if (G.sub.startsWith("cz_")) return head + czView();
  if (G.ed) return head + vDocEdit();
  if (G.sub === "tableau") return head + vTableau();
  if (G.sub === "planning") return head + vPlanning();
  if (G.sub === "releves") return head + vReleves();
  if (G.sub === "contrats") return head + vContrats();
  if (G.sub === "paye") return head + vPaye();
  if (G.sub === "marges") return head + vMarges();
  if (G.sub === "operations") return head + vOps();
  if (G.sub === "poste") return head + vPoste();
  if (G.sub === "rup") return head + vRup();
  if (G.sub === "maintenance") return head + vMaint();
  if (G.sub === "ndf") return head + vNdfAdm();
  if (G.sub === "clients") return head + vClients();
  if (G.sub === "reglages") return head + vReglages();
  return head + vDocs();
}
function bGestion(){
  document.querySelectorAll("[data-gs]").forEach(b => b.onclick = () => { G.sub = b.dataset.gs; G.ed = null; G.cli = null; G.mail = null; go("gestion"); });
  if (G.sub.startsWith("cz_")) return czBind();
  if (G.ed) return bDocEdit();
  if (G.sub === "tableau") return bTableau();
  if (G.sub === "planning") return bPlanning();
  if (G.sub === "releves") return bReleves();
  if (G.sub === "contrats") return bContrats();
  if (G.sub === "paye") return bPaye();
  if (G.sub === "marges") return bMarges();
  if (G.sub === "operations") return bOps();
  if (G.sub === "poste") return bPoste();
  if (G.sub === "rup") return bRup();
  if (G.sub === "maintenance") return bMaint();
  if (G.sub === "ndf") return bNdfAdm();
  if (G.sub === "clients") return bClients();
  if (G.sub === "reglages") return bReglages();
  bDocs();
}

/* ---------- Liste ---------- */
function vDocs(){
  const fac = G.sub === "factures", L = G.docs || [], S = G.ini.statuts;
  const pill = s => `<span class="pill ${s === "accepte" || s === "payee" ? "p-ok" : s === "refuse" ? "p-bad" : s === "brouillon" ? "" : "p-warn"}">${esc(S[s] || s)}</span>`;
  return `<div class="card tablebox"><div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><h2>${fac ? "Factures" : "Devis"} (${L.length})</h2>
      ${fac ? `<div class="hint">Une facture se crée depuis son devis (bouton « Créer la facture »).</div>` : `<button class="primary" id="g-new">+ Nouveau devis</button>`}</div>
    ${fac ? `<details style="margin:6px 0 10px"><summary>Export comptable : journal des ventes</summary><div class="row3"><label>Du<input type="date" id="jv-du" value="${new Date().toISOString().slice(0, 8)}01"></label><label>Au<input type="date" id="jv-au" value="${new Date().toISOString().slice(0, 10)}"></label>
      <div style="align-self:end"><button class="ghost" id="jv-go">Télécharger (Excel)</button></div></div><div class="hint">Une écriture par facture émise : client (411), prestations (706), TVA collectée (44571). À transmettre à votre cabinet comptable ou à importer dans votre logiciel.</div></details>` : ""}
    ${L.length ? `<div class="tablewrap"><table><tr><th>N°</th><th>Date</th><th>Client</th><th>Objet</th><th class="num">Total HT</th><th class="num">TTC</th><th>Statut</th><th></th></tr>
      ${L.map(d => `<tr class="click" data-gopen="${d.id}"><td><b>${esc(d.numero || "brouillon")}</b>${d.avenant_de ? `<div class="hint">avenant</div>` : ""}</td><td>${fmtD(d.date_doc)}</td><td>${esc(d.client_nom)}</td><td>${esc(d.objet)}${d.pole === "hot" ? `<span class="tag-hot">Accueil</span>` : ""}</td>
        <td class="num">${gEur(d.total_ht)}</td><td class="num">${gEur(d.total_ttc)}</td><td>${pill(d.statut)}${d.accepte_le ? `<div class="hint">${d.statut === "accepte" ? "validé" : "refusé"} ${d.accepte_ip === "e-mail" ? "par e-mail" : "en ligne"} le ${esc(d.accepte_le.slice(8, 10) + "/" + d.accepte_le.slice(5, 7))}</div>` : d.envoye_le ? `<div class="hint">envoyé le ${esc(d.envoye_le.slice(8, 10) + "/" + d.envoye_le.slice(5, 7))}</div>` : ""}${!fac && d.facture_id ? `<div class="hint">facturé</div>` : ""}</td>
        <td><a class="btn ghost" href="gestion_api.php?action=pdf&id=${d.id}" target="_blank" rel="noopener" data-stop>PDF</a></td></tr>`).join("")}</table></div>`
      : `<div class="empty">${fac ? "Aucune facture pour l'instant." : "Aucun devis pour l'instant. Créez le premier."}</div>`}</div>`;
}
function bDocs(){
  if ($("#jv-go")) $("#jv-go").onclick = () => { location.href = "gestion_api.php?action=journal_ventes&du=" + $("#jv-du").value + "&au=" + $("#jv-au").value; };
  if ($("#g-new")) $("#g-new").onclick = () => { G.ed = newDoc(); go("gestion"); };
  document.querySelectorAll("[data-stop]").forEach(a => a.onclick = e => e.stopPropagation());
  document.querySelectorAll("[data-gopen]").forEach(tr => tr.onclick = async () => { try { G.ed = (await gapi("doc", null, "id=" + tr.dataset.gopen)).doc; G.mail = null; go("gestion"); } catch(e) { toast(e.message); } });
}
function newDoc(){
  const r = G.ini.reglages, c = G.ini.clients[0];
  return {id:0, type:"devis", pole:POLES_ACTIFS()[0], numero:"", client_id:"", date_doc:gToday(), validite:gAdd(gToday(), +r.validite_jours || 30), paiement:"30j", acompte:0, tva:"20", zone:"France", objet:"",
    mention:r.mention_presta, notes:"", statut:"brouillon", tva_mention:"", lignes:[newPresta("France", "base")]};
}
const tarifs = () => G.ini.reglages.tarifs || [];
/** Jour férié ajouté : payé non travaillé (tarif « fériés payés ») ou majoré ×2 d'office (sauf si la case « majorer » est décochée). */
function regleFerie(l, iso, zone){ if (!ferie(zone, iso)) return; l.fnt = l.fnt || [];
  if (tarif(l.tarif)?.feries_payes && l.fntOn !== false) { if (!l.fnt.includes(iso)) l.fnt.push(iso); }
  else if (l.majFeries !== false && !l.maj.includes(iso)) l.maj.push(iso); }
const tarif = id => tarifs().find(t => t.id === id);
/** Applique un tarif des réglages : prix de la journée, horaires, intitulé du poste. */
function applyTarif(l, id){ const t = tarif(id); l.tarif = id || ""; if (!t) return l;
  const old = tarif(l._t); if (!l.poste || (old && l.poste === old.poste)) l.poste = t.poste || l.poste;
  l.prix_jour = t.prix; l.debut = t.debut; l.fin = t.fin; l.pause = t.pause; l._t = id; return autoPause(l); }
/** Journée de 6 h ou moins : sans pause par défaut ; au-delà, 1 h si aucune pause n'était prévue. */
const hm = s => { const m = /^(\d{1,2})[:h](\d{2})$/.exec(String(s || "").trim()); return m ? +m[1] * 60 + +m[2] : 0; };
function autoPause(l){ const amp = hm(l.fin) - hm(l.debut); if (amp > 0 && amp <= 360) l.pause = 0; else if (amp > 360 && !+l.pause) l.pause = 60; return l; }
function newPresta(zone, id){ const c = G.ini.clients.find(x => +x.id === +(G.ed && G.ed.client_id));
  // L'intitulé et le rôle de la première prestation sont repris par défaut (modifiables).
  const p0 = G.ed && G.ed.lignes ? G.ed.lignes.find(l => l.type === "presta" && (l.poste || "").trim()) : null;
  const l = applyTarif({type:"presta", poste:"", role:ROLES_P()[0], zone, magasins:[], debut:"10:00", fin:"18:00", pause:60, dates:[], maj:[], fnt:[], prix_jour:""}, id || (c && c.tarif) || "base");
  if (p0) { l.poste = p0.poste; l.role = p0.role || l.role; } return l; }

/* ---------- Éditeur ---------- */
const UE = ["allemagne","autriche","belgique","bulgarie","chypre","croatie","danemark","espagne","estonie","finlande","grece","hongrie","irlande","italie","lettonie","lituanie","luxembourg","malte","pays-bas","pays bas","pologne","portugal","republique tcheque","tchequie","roumanie","slovaquie","slovenie","suede"];
const TVA_TXT = {hors_ue:"TVA non applicable – art. 259-1 du CGI", ue:"Autoliquidation – art. 283-2 du CGI / art. 196 de la directive 2006/112/CE"};
function tvaMentionTxt(d, c){ if (+d.tva) return ""; let m = d.tva_mention || "";
  if (!m) { const p = String((c && c.pays) || "France").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().trim(); m = !p || p === "france" ? "" : UE.includes(p) ? "ue" : "hors_ue"; }
  return TVA_TXT[m] || ""; }
const locked = () => G.ed.type === "facture" && !!G.ed.numero;
/** Anciens devis : un seul magasin en texte ; désormais une liste de points d'intervention (toutes zones). */
const mags = l => { if (!Array.isArray(l.magasins)) l.magasins = l.magasin ? [{id:l.magasin_id || 0, nom:l.magasin}] : []; return l.magasins; };
/* Merchandising : à la visite, en tournée (un créneau par jour pour tous les points), kilomètres de l'itinéraire facturés. */
const tournee = l => !!+l.tournee && mags(l).length > 1;
const lineMult = l => tournee(l) && l.unite !== "visite" ? 1 : Math.max(1, mags(l).length);
const lineKm = l => { const j = l.dates.filter(d => !(l.fnt || []).includes(d)).length; return tournee(l) ? {j, km:gNum(l.km) * j, total:gNum(l.km) * j * gNum(l.prix_km)} : {j, km:0, total:0}; };
function lineTotal(l){ if (l.type === "presta") return ((l.dates.length - l.maj.length) * gNum(l.prix_jour) + l.maj.length * 2 * gNum(l.prix_jour)) * lineMult(l) + lineKm(l).total; if (l.type === "libre") return gNum(l.qte) * gNum(l.pu); return 0; }
function totals(){ const ht = G.ed.lignes.reduce((a, l) => a + lineTotal(l), 0), tv = Math.round(ht * gNum(G.ed.tva)) / 100; return {ht, tv, ttc:ht + tv}; }
function cal(i){
  const l = G.ed.lignes[i], key = "c" + i; if (!G.cal[key]) { const f = l.dates[0] || gToday(); G.cal[key] = f.slice(0, 7); }
  const [y, m] = G.cal[key].split("-").map(Number), first = new Date(y, m - 1, 1), off = (first.getDay() + 6) % 7, n = new Date(y, m, 0).getDate(), zone = l.zone || G.ed.zone;
  let cells = ""; for (let k = 0; k < off; k++) cells += `<span></span>`;
  for (let d = 1; d <= n; d++) {
    const iso = `${y}-${String(m).padStart(2, "0")}-${String(d).padStart(2, "0")}`, f = ferie(zone, iso), sel = l.dates.includes(iso), mj = l.maj.includes(iso), sun = (off + d - 1) % 7 === 6;
    cells += `<button type="button" class="gd${sel ? " on" : ""}${mj ? " mj" : ""}${f ? " fe" : ""}${sun ? " su" : ""}" data-gday="${i}" data-iso="${iso}" title="${esc(f || (sun ? "Dimanche" : ""))}" ${locked() ? "disabled" : ""}>${d}</button>`;
  }
  return `<div class="gcal"><div class="gcal-h"><button type="button" class="ghost" data-gcm="${i}" data-dir="-1">‹</button><b>${MOIS[m - 1]} ${y}</b><button type="button" class="ghost" data-gcm="${i}" data-dir="1">›</button></div>
    <div class="gcal-g">${JOURS.map(j => `<i>${j}</i>`).join("")}${cells}</div>
    <div class="hint">Cliquez les jours travaillés. <span class="gk fe"></span> férié · <span class="gk mj"></span> majoré 100 % · un jour férié coché est majoré ×2 d'office (case « ×2 » sur la date pour l'enlever).</div></div>`;
}
function vPresta(l, i){
  const ro = locked() ? "disabled" : "", zone = l.zone || G.ed.zone, M = mags(l), pris = new Set(M.map(m => m.nom));
  const zs = [zone].concat(A.L.zones.filter(z => z !== zone));
  return `<div class="gline" data-gi="${i}"><div class="gline-h"><b>Prestation ${i + 1}</b><span class="gline-t">${gEur(lineTotal(l))}</span>${ro ? "" : `${M.length > 1 ? `<button type="button" class="ghost" data-gsplit="${i}" title="Une ligne par point d'intervention : chaque magasin garde ses propres dates">Séparer par point d'intervention</button>` : ""}<button type="button" class="ghost" data-gdup="${i}">Dupliquer</button><button type="button" class="ghost" data-gdel="${i}">Retirer</button>`}</div>
    ${M.length > 1 && G.ed.type === "facture" && !ro ? `<div class="hint" style="margin:-2px 0 6px">Une date à retirer pour un seul magasin ? Cliquez « Séparer par point d'intervention » : chaque magasin aura sa ligne et ses dates.</div>` : ""}
    <div class="row3"><label>Tarif <span class="hint">· réglages</span><select data-gf="tarif" ${ro}><option value="">Prix libre</option>${tarifs().map(t => `<option value="${esc(t.id)}" ${l.tarif === t.id ? "selected" : ""}>${esc(t.nom)} · ${gEur(t.prix)}</option>`).join("")}</select></label>
      ${(tarif(l.tarif)?.extras || []).length && !ro ? `<div style="grid-column:span 2;align-self:end" class="btns">${tarif(l.tarif).extras.map((x, k) => `<button type="button" class="ghost" data-gext="${i}" data-k="${k}">+ ${esc(x.designation)} (${gEur(x.pu)})</button>`).join("")}</div>` : "<span></span><span></span>"}</div>
    <div class="row3"><label>Intitulé du poste<input data-gf="poste" value="${esc(l.poste)}" placeholder="Ex. Hôtesse bilingue salon" ${ro}></label>
      <label>Rôle <span class="hint">· texte libre</span><input data-gf="role" list="g-roles" value="${esc(l.role)}" placeholder="Intervenant(e)" ${ro}></label>
      <label>Zone<select data-gf="zone" ${ro}>${A.L.zones.map(z => `<option ${z === zone ? "selected" : ""}>${z}</option>`).join("")}</select></label></div>
    <div class="muted" style="margin:4px 0">Points d'intervention <span class="hint">· autant que vous voulez, toutes zones : mêmes dates et horaires dans chacun, chaque point d'intervention est compté</span></div>
    <div class="gchips" style="margin-bottom:6px">${M.map((m, k) => `<span class="gchip">${tournee(l) ? `<b>${k + 1}.</b> ` : ""}${esc(m.nom)}${ro ? "" : `${tournee(l) && k ? `<button type="button" data-gmup="${i}" data-k="${k}" title="Passer avant dans l'itinéraire">↑</button>` : ""}<button type="button" data-gmx="${i}" data-k="${k}">✕</button>`}</span>`).join("") || `<span class="hint">Aucun point d'intervention choisi.</span>`}</div>
    ${ro ? "" : `<div class="row3"><label>Ajouter un point d'intervention<select data-gmadd="${i}"><option value="">Choisir…</option>${zs.map(z => `<optgroup label="${esc(z)}">${A.L.magasins.filter(m => m.zone === z && +m.actif && !pris.has(m.nom)).map(m => `<option value="${m.id}">${esc(m.nom)}${m.ville ? " · " + esc(m.ville) : ""}</option>`).join("")}<option value="tous:${esc(z)}">Tous les points d'intervention de ${esc(z)}</option></optgroup>`).join("")}</select></label>
      <label>Ou un autre lieu (saisie libre)<input data-gmlibre="${i}" placeholder="Ex. Parc des expositions, puis Entrée"></label><span></span></div>`}
    <div class="row3"><label>Facturation<select data-gf="unite" ${ro}><option value="jour">À la journée</option><option value="visite" ${l.unite === "visite" ? "selected" : ""}>À la visite (merchandising)</option></select></label>
      <label>${l.unite === "visite" ? "Prix d'une visite HT (€)" : "Prix d'une journée HT (€)"}<input data-gf="prix_jour" inputmode="decimal" value="${esc(String(l.prix_jour).replace(".", ","))}" placeholder="${l.unite === "visite" ? "Ex. 65" : "Ex. 205"}" ${ro}></label>
      <div class="hint" style="align-self:end">${l.unite === "visite" ? (tournee(l) ? M.length + " visites par jour de tournée" : "une visite = un point d'intervention, une date") : tournee(l) ? "une journée pour toute la tournée" : M.length > 1 ? "une journée par point d'intervention" : ""}</div></div>
    ${M.length > 1 ? `<label class="ck gopt"><input type="checkbox" data-gf="tournee" ${tournee(l) ? "checked" : ""} ${ro}><span><b>Tournée</b> : une seule personne fait tous ces points d'intervention dans la journée, dans l'ordre ci-dessus (merchandising, nettoyage de meubles, relevés)</span></label>
      ${tournee(l) ? `<div class="gtour"><div class="hint">Itinéraire : ${M.map(m => esc(m.nom)).join(" → ")}${ro ? "" : ` · flèches ↑ pour changer l'ordre`}</div>
        <div class="row3"><label>Km de l'itinéraire par jour<input data-gf="km" inputmode="decimal" value="${esc(String(l.km || "").replace(".", ","))}" placeholder="Ex. 42" ${ro}></label>
          <label>Prix du km facturé HT (€)<input data-gf="prix_km" inputmode="decimal" value="${esc(String(l.prix_km || "").replace(".", ","))}" placeholder="Ex. 0,60" ${ro}></label>
          <div style="align-self:end">${ro ? "" : `<button type="button" class="ghost" data-gkm="${i}">Calculer l'itinéraire</button>`}<div class="hint">${lineKm(l).total ? gEur(lineKm(l).total) + " de kilomètres (" + String(Math.round(lineKm(l).km * 10) / 10).replace(".", ",") + " km sur " + lineKm(l).j + " jour" + (lineKm(l).j > 1 ? "s" : "") + ")" : "facturés en plus, sur une ligne à part"}</div></div></div></div>` : ""}` : ""}
    <div class="row3"><label>Début<input type="time" data-gf="debut" value="${esc(l.debut)}" ${ro}></label><label>Fin<input type="time" data-gf="fin" value="${esc(l.fin)}" ${ro}></label>
      <label>Pause<select data-gf="pause" ${ro}>${[0, 30, 45, 60, 90, 120].map(p => `<option value="${p}" ${+l.pause === p ? "selected" : ""}>${p ? (p >= 60 ? Math.floor(p / 60) + "h" + (p % 60 ? String(p % 60).padStart(2, "0") : "") : p + " min") : "sans pause"}</option>`).join("")}</select></label></div>
    <div class="gcal-wrap">${cal(i)}
      <div class="gper">${ro ? "" : `<div class="muted" style="margin-bottom:4px">Ou une période</div><div class="row"><label>Du<input type="date" data-gp="du"></label><label>Au<input type="date" data-gp="au"></label></div>
        <div class="chips multi" data-gpj>${JOURS.map((j, k) => `<button type="button" class="chip" data-v="${k}" aria-pressed="${k === 5}">${j}</button>`).join("")}</div>
        <div class="btns"><button type="button" class="ghost" data-gpadd="${i}">Ajouter ces dates</button>${l.dates.length ? `<button type="button" class="ghost" data-gpclr="${i}">Tout effacer</button>` : ""}</div>`}
        ${ro ? "" : `<label class="ck gopt"><input type="checkbox" data-gmajall="${i}" ${l.majFeries !== false ? "checked" : ""}><span>Majorer à 100 % les jours fériés travaillés</span></label>
        ${tarif(l.tarif)?.feries_payes ? `<label class="ck gopt"><input type="checkbox" data-gfntall="${i}" ${l.fntOn !== false ? "checked" : ""}><span>Compter les jours fériés non travaillés mais payés</span></label>` : ""}`}
        <div class="muted" style="margin:8px 0 4px">${l.dates.length} journée${l.dates.length > 1 ? "s" : ""}${l.maj.length ? ` dont ${l.maj.length} majorée${l.maj.length > 1 ? "s" : ""} à 100 %` : ""}</div>
        <div class="gchips">${l.dates.map(d => `<span class="gchip${l.maj.includes(d) ? " mj" : ""}${(l.fnt || []).includes(d) ? " fnt" : ""}">${gDay(d)}${ferie(zone, d) ? ` <i>${esc(ferie(zone, d))}</i>` : ""}${(l.fnt || []).includes(d) ? ` <i>non travaillé, payé</i>` : ""}${ro ? "" : `${ferie(zone, d) || new Date(d + "T12:00:00").getDay() === 0 ? `<label class="gck" title="Majoration 100 %"><input type="checkbox" data-gmaj="${i}" data-iso="${d}" ${l.maj.includes(d) ? "checked" : ""}>×2</label>` : ""}${ferie(zone, d) && tarif(l.tarif)?.feries_payes ? `<label class="gck" title="Férié non travaillé mais payé"><input type="checkbox" data-gfnt="${i}" data-iso="${d}" ${(l.fnt || []).includes(d) ? "checked" : ""}>payé</label>` : ""}<button type="button" data-gx="${i}" data-iso="${d}">✕</button>`}</span>`).join("")}</div></div></div></div>`;
}
function vLigne(l, i){
  const ro = locked() ? "disabled" : "";
  if (l.type === "presta") return vPresta(l, i);
  if (l.type === "texte") return `<div class="gline" data-gi="${i}"><div class="gline-h"><b>Texte</b>${ro ? "" : `<button type="button" class="ghost" data-gdel="${i}">Retirer</button>`}</div><textarea data-gf="texte" rows="2" ${ro}>${esc(l.texte)}</textarea></div>`;
  return `<div class="gline" data-gi="${i}"><div class="gline-h"><b>Ligne libre</b><span class="gline-t">${gEur(lineTotal(l))}</span>${ro ? "" : `<button type="button" class="ghost" data-gdel="${i}">Retirer</button>`}</div>
    <div class="row3"><label>Désignation<input data-gf="designation" value="${esc(l.designation)}" placeholder="Ex. Formation, frais de déplacement…" ${ro}></label><label>Quantité<input data-gf="qte" inputmode="decimal" value="${esc(String(l.qte).replace(".", ","))}" ${ro}></label><label>Prix unitaire HT (€)<input data-gf="pu" inputmode="decimal" value="${esc(String(l.pu).replace(".", ","))}" ${ro}></label></div></div>`;
}
function vDocEdit(){
  const d = G.ed, I = G.ini, fac = d.type === "facture", ro = locked() ? "disabled" : "", t = totals(), c = I.clients.find(x => +x.id === +d.client_id);
  const cli = c || (d.client && d.client.raison ? d.client : null);
  return `<div class="btns"><button class="ghost" id="g-back">‹ ${fac ? "Factures" : "Devis"}</button></div>
  <div class="card accent"><div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap"><div><h2>${fac ? "Facture" : "Devis"} ${esc(d.numero || (fac ? "(provisoire, numéro à l'émission)" : "(nouveau)"))}</h2>
      ${fac && !d.numero ? `<div class="muted">Reprise du devis : retirez ou changez les journées non faites, puis émettez la facture.</div>` : ""}${locked() ? `<div class="muted">Facture émise : elle ne se modifie plus.</div>` : ""}</div>
      <div class="gtot"><span>Total HT <b>${gEur(t.ht)}</b></span><span>TVA <b>${gEur(t.tv)}</b></span><span class="ttc">TTC <b>${gEur(t.ttc)}</b></span></div></div>
    ${POLES_ACTIFS().length > 1 || (d.pole && d.pole !== POLES_ACTIFS()[0]) ? `<div class="gpole">${Object.entries(I.poles || {}).filter(([k]) => POLES_ACTIFS().includes(k) || k === d.pole).map(([k, l]) => `<label class="ck"><input type="radio" name="g-pole" value="${k}" ${(d.pole || "anim") === k ? "checked" : ""} ${ro || (fac ? "disabled" : "")}><span>${esc(l)}</span></label>`).join("")}
      <span class="hint">Fixe le contrat de travail, les postes proposés et le taux de charges des marges.</span></div>` : ""}
    <div class="row3"><label>Type d'opération<select id="g-type" ${ro}><option value="">Choisir…</option>${((I.types_op || {})[d.pole || POLES_ACTIFS()[0]] || []).map(t => `<option ${d.type_op === t ? "selected" : ""}>${esc(t)}</option>`).join("")}</select></label><span></span><span></span></div>
    <div class="row3"><label>Client<select id="g-cli" ${ro}><option value="">Choisir…</option>${I.clients.map(x => `<option value="${x.id}" ${+x.id === +d.client_id ? "selected" : ""}>${esc(x.raison)}${x.contact ? " · " + esc(x.contact) : ""}</option>`).join("")}</select></label>
      <label>${fac ? "Date de facture" : "Date du devis"}<input type="date" id="g-date" value="${d.date_doc}" ${ro}></label>
      ${fac ? `<label>Échéance<input type="date" value="${d.echeance || ""}" disabled></label>` : `<label>Valable jusqu'au<input type="date" id="g-val" value="${d.validite || ""}" ${ro}></label>`}</div>
    ${cli ? `<div class="gcli"><b>${esc(cli.raison)}</b>${cli.contact ? ` · à l'attention de ${esc(cli.contact)}` : ""}<br>${esc(cli.adresse || "")} ${esc(cli.cp || "")} ${esc(cli.ville || "")}${cli.pays && cli.pays !== "France" ? ", " + esc(cli.pays) : ""}${cli.emails ? `<br><span class="muted">${esc(cli.emails)}</span>` : ""}</div>` : ""}
    ${ro ? "" : `<button type="button" class="linkbtn" id="g-cli-new">+ Nouveau client</button>`}
    <div class="row3"><label>Conditions de paiement<select id="g-pay" ${ro}>${Object.entries(I.paiements).map(([k, l]) => `<option value="${k}" ${d.paiement === k ? "selected" : ""}>${l}</option>`).join("")}</select></label>
      <label>TVA<select id="g-tva" ${ro}>${Object.entries(I.tva).map(([k, l]) => `<option value="${k}" ${String(+d.tva) === String(+k) ? "selected" : ""}>${l}</option>`).join("")}</select></label>
      <label>Acompte à la commande (%)<input id="g-acpt" inputmode="decimal" value="${+d.acompte || 0}" ${ro}></label></div>
    ${!+d.tva ? `<div class="row3"><label style="grid-column:span 2">Mention TVA 0 % <span class="hint">· obligatoire sur le document</span><select id="g-tvam" ${ro}>${Object.entries(I.tva_mentions || {}).map(([k, l]) => `<option value="${k}" ${(d.tva_mention || "") === k ? "selected" : ""}>${esc(l)}</option>`).join("")}</select></label>
      <div class="hint" style="align-self:end">${tvaMentionTxt(d, cli) ? "Imprimé : <b>" + esc(tvaMentionTxt(d, cli)) + "</b>" : `<span style="color:#A33">Aucune mention imprimée : choisissez-en une si la TVA n'est pas due.</span>`}</div></div>` : ""}
    <div class="row3"><label>Référence du client<select id="g-reft" ${ro}>${Object.entries(I.refs || {}).map(([k, l]) => `<option value="${k}" ${(d.ref_type || "") === k ? "selected" : ""}>${l}</option>`).join("")}</select></label>
      <label>Numéro <span class="hint">· imprimé sur le devis et la facture</span><input id="g-refn" value="${esc(d.ref_num || "")}" placeholder="Ex. 4500123456" ${ro}></label><span></span></div>
    <div class="row3"><label>Zone<select id="g-zone" ${ro}>${A.L.zones.map(z => `<option ${z === d.zone ? "selected" : ""}>${z}</option>`).join("")}</select></label>
      <label style="grid-column:span 2">Objet <span class="hint">· <a href="#" id="g-obj-auto">proposer</a></span><input id="g-objet" value="${esc(d.objet)}" placeholder="Ex. Accueil salon professionnel, mars 2027" ${ro}></label></div></div>
  <datalist id="g-roles">${ROLES_P().map(r => `<option value="${esc(r)}">`).join("")}</datalist>
  <div class="card"><h2>Lignes</h2>${d.lignes.map(vLigne).join("")}
    ${ro ? "" : `<div class="btns"><button type="button" class="ghost" id="g-add-p">+ Prestation (journées)</button><button type="button" class="ghost" id="g-add-l">+ Ligne libre</button><button type="button" class="ghost" id="g-add-t">+ Texte</button></div>`}
    <label>Mention sous les lignes <span class="hint">· {repas} et {km} reprennent les montants des réglages (${esc(I.reglages.repas)} € et ${esc(I.reglages.km)} €/km)</span><textarea id="g-mention" rows="3" ${ro}>${esc(d.mention ?? I.reglages.mention_presta)}</textarea></label>
    <label>Notes internes <span class="hint">· jamais sur le document</span><textarea id="g-notes" rows="2">${esc(d.notes || "")}</textarea></label></div>
  <div class="card"><div class="btns">
    ${ro ? "" : `<button class="primary" id="g-save">Enregistrer</button>`}
    <button class="ghost" id="g-pdf">Voir le PDF</button>
    ${!fac || d.numero ? `<button class="terra" id="g-mail">Envoyer par e-mail</button>` : ""}
    ${fac && !d.numero ? `<button class="terra" id="g-emit">Émettre la facture</button>` : ""}
    ${!fac && d.id ? `<button class="ghost" id="g-fac">${d.facture_id ? "Voir la facture" : "Créer la facture"}</button><button class="ghost" id="g-dup">Dupliquer</button>${d.statut === "accepte" || d.avenant_de ? `<button class="ghost" id="g-aven" title="Jours supplémentaires demandés par le client">+ Avenant au devis</button>` : ""}` : ""}
    ${d.id && !locked() ? `<button class="ghost danger" id="g-del">Supprimer</button>` : ""}</div>
    ${d.id ? `<div class="row3" style="margin-top:8px"><label>Statut<select id="g-st">${(fac ? (d.numero ? ["emise", "payee"] : []) : ["brouillon", "envoye", "accepte", "refuse"]).map(s => `<option value="${s}" ${d.statut === s ? "selected" : ""}>${esc(I.statuts[s])}</option>`).join("")}</select></label>
      <div class="hint" style="align-self:end">${d.envoye_le ? "Envoyé le " + esc(d.envoye_le.slice(0, 16).replace(" ", " à ")) + " à " + esc(d.envoye_a) : "Pas encore envoyé."}</div></div>
      ${d.accepte_le ? `<div class="${d.statut === "accepte" ? "dv-ok" : "muted"}" style="margin-top:8px">${d.accepte_ip === "e-mail" ? "Accord du client par e-mail" : (d.statut === "accepte" ? "Validé" : "Refusé") + " en ligne par le client"} le ${esc(d.accepte_le.slice(0, 16).replace(" ", " à "))} : ${esc(d.accepte_par)}${d.refus_motif ? " · motif : " + esc(d.refus_motif) : ""}${d.accepte_preuve ? ` · <a href="gestion_api.php?action=preuve&id=${d.id}" target="_blank" rel="noopener">voir l'e-mail joint</a>` : ""}</div>` : ""}
      ${!fac && d.statut !== "accepte" ? (G.accMail ? vAccMail() : `<div class="btns" style="margin-top:8px"><button class="ghost" id="g-accm">Le client a accepté par e-mail…</button></div>`) : ""}` : ""}
    ${G.mail ? vMail() : ""}</div>
  ${G.cli ? `<div class="card" id="g-cli-box"><h2>Nouveau client</h2>${clientForm({})}</div>` : ""}`;
}
function vAccMail(){
  const now = new Date(Date.now() - new Date().getTimezoneOffset() * 6e4).toISOString().slice(0, 16), c = G.ed.client || {};
  return `<div class="gmail"><h2 style="margin-top:12px">Accord du client par e-mail</h2>
    <div class="muted">${AG} enregistre l'acceptation pour le client. Gardez la preuve : l'e-mail du client (Outlook : Fichier › Enregistrer sous, en .msg ou .eml ; sinon en PDF ou une capture). Un accord écrit par e-mail vaut acceptation du devis.</div>
    <div class="row3"><label>Reçu le<input type="datetime-local" id="ga-le" value="${now}" max="${now}"></label><label>De (adresse e-mail)<input id="ga-de" value="${esc((c.emails || "").split(",")[0].trim())}"></label><label>Nom<input id="ga-nom" value="${esc(c.contact || "")}"></label></div>
    <label>E-mail du client<input type="file" id="ga-f" accept=".eml,.msg,.pdf,.png,.jpg,.jpeg,.txt"></label>
    <div class="btns"><button class="terra" id="ga-go">Enregistrer l'accord</button><button class="ghost" id="ga-x">Annuler</button></div></div>`;
}
function vMail(){
  const m = G.mail;
  return `<div class="gmail"><h2 style="margin-top:12px">Envoyer au client</h2><div class="row"><label>À ${m.to ? "" : `<span class="hint" style="color:#A33">· ce client n'a pas d'e-mail : tapez l'adresse ici (ou ajoutez-la dans Clients)</span>`}<input id="gm-to" value="${esc(m.to)}" placeholder="adresse@client.com"></label><label>Copie (Cc)<input id="gm-cc" value="${esc(m.cc)}"></label></div>
    <label>Objet de l'e-mail<input id="gm-suj" value="${esc(m.sujet)}"></label><label>Message<textarea id="gm-msg" rows="10">${esc(m.message)}</textarea></label>
    <div class="hint">Le ${G.ed.type === "facture" ? "facture" : "devis"} part en PDF joint${G.ed.type === "devis" ? ", avec les conditions générales de vente" : ""}.</div>
    <div class="btns"><button class="terra" id="gm-go">Envoyer</button><button class="ghost" id="gm-x">Annuler</button></div></div>`;
}
/** Lit le formulaire dans G.ed (sans perdre les dates cochées). */
function readEd(){
  const d = G.ed; if (locked()) { d.notes = $("#g-notes")?.value || d.notes; return; }
  d.client_id = $("#g-cli").value; const pr = document.querySelector("[name=g-pole]:checked"); if (pr) d.pole = pr.value; if ($("#g-type")) d.type_op = $("#g-type").value; d.date_doc = $("#g-date").value; if ($("#g-val")) d.validite = $("#g-val").value;
  d.paiement = $("#g-pay").value; d.ref_type = $("#g-reft").value; d.ref_num = $("#g-refn").value.trim(); d.tva = $("#g-tva").value; d.acompte = gNum($("#g-acpt").value); d.zone = $("#g-zone").value; d.objet = $("#g-objet").value.trim();
  d.mention = $("#g-mention").value; d.notes = $("#g-notes").value; if ($("#g-tvam")) d.tva_mention = $("#g-tvam").value;
  document.querySelectorAll(".gline").forEach(box => { const l = d.lignes[+box.dataset.gi]; if (!l) return;
    box.querySelectorAll("[data-gf]").forEach(f => { if (!f.closest(".gline") || f.closest(".gline") !== box) return; l[f.dataset.gf] = f.type === "checkbox" ? (f.checked ? 1 : 0) : f.value; });
    if (l.type === "presta") { l.pause = +l.pause; mags(l); } });
}
const redraw = () => { const y = window.scrollY; $("#view").innerHTML = shell(vGestion()); document.querySelectorAll("[data-tab]").forEach(b => b.onclick = () => go(b.dataset.tab)); bGestion(); window.scrollTo(0, y); };
async function saveEd(){
  readEd(); const d = G.ed;
  const r = await gapi("doc_save", {id:d.id, type:d.type, client_id:d.client_id, date_doc:d.date_doc, validite:d.validite, paiement:d.paiement, tva:d.tva, acompte:d.acompte, zone:d.zone, pole:d.pole || "anim", type_op:d.type_op || "", objet:d.objet, ref_type:d.ref_type, ref_num:d.ref_num, tva_mention:d.tva_mention || "",
    mention:d.mention, notes:d.notes, lignes:d.lignes});
  const keepFac = d.facture_id; G.ed = r.doc; G.ed.facture_id = keepFac; return r.doc;
}
function bDocEdit(){
  const d = G.ed, I = G.ini;
  // Changement de pôle : les postes de l'autre pôle sont remplacés par le premier poste du nouveau pôle.
  document.querySelectorAll("[name=g-pole]").forEach(r => r.onchange = () => { readEd(); const L = ROLES_P();
    d.lignes.forEach(l => { if (l.type === "presta" && !L.includes(l.role)) l.role = L[0]; }); redraw(); });
  $("#g-back").onclick = () => { G.ed = null; G.mail = null; G.cli = null; G.accMail = false; go("gestion"); };
  const field = (sel, ev = "change") => { const e = $(sel); if (e) e["on" + ev] = () => { readEd(); redraw(); }; };
  if (!locked()) {
    $("#g-cli").onchange = () => { readEd(); const c = I.clients.find(x => +x.id === +d.client_id); if (c) { d.tva = c.tva; d.paiement = c.paiement; d.client = c;
      // Tarif du client sur les prestations pas encore datées.
      if (c.tarif) d.lignes.forEach(l => { if (l.type === "presta" && !l.dates.length) applyTarif(l, c.tarif); }); } redraw(); };
    ["#g-tva", "#g-zone", "#g-tvam"].forEach(s => field(s));
    $("#g-acpt").onchange = () => readEd();
    $("#g-cli-new").onclick = () => { readEd(); G.cli = true; redraw(); $("#g-cli-box").scrollIntoView({behavior:"smooth"}); };
    $("#g-obj-auto").onclick = e => { e.preventDefault(); readEd(); const p = d.lignes.find(l => l.type === "presta" && l.dates.length), c = I.clients.find(x => +x.id === +d.client_id);
      if (!p) return toast("Ajoutez d'abord une prestation avec des dates."); const ds = [...p.dates].sort(), a = ds[0], z = ds[ds.length - 1], mo = MOIS[+a.slice(5, 7) - 1] + " " + a.slice(0, 4);
      const br = c ? c.raison.split(" ")[0] : "", po = (p.poste || "animation"), poL = br && po.toLowerCase().includes(br.toLowerCase()) ? po.replace(new RegExp("\\s*" + br + "\\s*", "i"), " ").trim() : po;
      d.objet = `${br ? br + " " : ""}${poL.replace(/^\w/, x => x.toLowerCase())}${mags(p).length === 1 ? " " + mags(p)[0].nom : mags(p).length > 1 ? " dans " + mags(p).length + " points d'intervention" : ""} en ${mo} · ${a === z ? "le " + a.slice(8, 10) + "/" + a.slice(5, 7) : "du " + a.slice(8, 10) + "/" + a.slice(5, 7) + " au " + z.slice(8, 10) + "/" + z.slice(5, 7) + "/" + z.slice(0, 4)}`; redraw(); };
    $("#g-add-p").onclick = () => { readEd(); d.lignes.push(newPresta(d.zone)); redraw(); };
    $("#g-add-l").onclick = () => { readEd(); d.lignes.push({type:"libre", designation:"", qte:1, pu:""}); redraw(); };
    $("#g-add-t").onclick = () => { readEd(); d.lignes.push({type:"texte", texte:""}); redraw(); };
    document.querySelectorAll("[data-gmadd]").forEach(sel => sel.onchange = () => { readEd(); const l = d.lignes[+sel.dataset.gmadd], v = sel.value; if (!v) return;
      const add = m => { if (!mags(l).some(x => x.nom === m.nom)) l.magasins.push({id:+m.id, nom:m.nom}); };
      if (v.startsWith("tous:")) A.L.magasins.filter(m => m.zone === v.slice(5) && +m.actif).forEach(add); else { const m = A.L.magasins.find(x => +x.id === +v); if (m) add(m); } redraw(); });
    document.querySelectorAll("[data-gmlibre]").forEach(inp => inp.onkeydown = e => { if (e.key !== "Enter") return; e.preventDefault(); const v = inp.value.trim(); if (!v) return; readEd(); const l = d.lignes[+inp.dataset.gmlibre]; if (!mags(l).some(x => x.nom === v)) l.magasins.push({id:0, nom:v}); redraw(); });
    document.querySelectorAll("[data-gmlibre]").forEach(inp => inp.onchange = () => { const v = inp.value.trim(); if (!v) return; readEd(); const l = d.lignes[+inp.dataset.gmlibre]; if (!mags(l).some(x => x.nom === v)) l.magasins.push({id:0, nom:v}); redraw(); });
    document.querySelectorAll("[data-gmx]").forEach(b => b.onclick = () => { readEd(); mags(d.lignes[+b.dataset.gmx]).splice(+b.dataset.k, 1); redraw(); });
    document.querySelectorAll("[data-gdup]").forEach(b => b.onclick = () => { readEd(); const c = JSON.parse(JSON.stringify(d.lignes[+b.dataset.gdup])); c.magasins = []; d.lignes.splice(+b.dataset.gdup + 1, 0, c); toast("Prestation dupliquée : choisissez ses points d'intervention"); redraw(); });
    document.querySelectorAll("[data-gdel]").forEach(b => b.onclick = () => { readEd(); d.lignes.splice(+b.dataset.gdel, 1); redraw(); });
    document.querySelectorAll("[data-gmup]").forEach(b => b.onclick = () => { readEd(); const M = mags(d.lignes[+b.dataset.gmup]), k = +b.dataset.k; [M[k - 1], M[k]] = [M[k], M[k - 1]]; d.lignes[+b.dataset.gmup].km = ""; redraw(); });
    document.querySelectorAll("[data-gkm]").forEach(b => b.onclick = async () => { readEd(); const l = d.lignes[+b.dataset.gkm]; b.disabled = true; b.textContent = "Calcul…";
      try { const r = await gapi("itineraire", {magasins:mags(l).map(m => m.nom)}); l.km = r.km; toast("Itinéraire : " + String(r.km).replace(".", ",") + " km" + (r.approx ? " (au moins un point mal localisé : à vérifier)" : "")); } catch(e) { toast(e.message); } redraw(); });
    document.querySelectorAll(".gline [data-gf]").forEach(f => f.onchange = () => {
      const i = +f.closest(".gline").dataset.gi, avant = d.lignes[i] ? d.lignes[i][f.dataset.gf] : "";
      readEd(); const l = d.lignes[i];
      if (f.dataset.gf === "tarif") applyTarif(l, f.value);
      if (f.dataset.gf === "debut" || f.dataset.gf === "fin") autoPause(l);
      // Intitulé ou rôle changé : repris sur les autres prestations qui avaient le même (ou rien).
      if ((f.dataset.gf === "poste" || f.dataset.gf === "role") && l.type === "presta") { let n = 0;
        d.lignes.forEach((x, k) => { if (k !== i && x.type === "presta" && (!(x[f.dataset.gf] || "").trim() || x[f.dataset.gf] === avant)) { x[f.dataset.gf] = l[f.dataset.gf]; n++; } });
        if (n) toast((f.dataset.gf === "poste" ? "Intitulé" : "Rôle") + " repris sur " + n + " autre" + (n > 1 ? "s" : "") + " prestation" + (n > 1 ? "s" : "")); }
      redraw(); });
    document.querySelectorAll("[data-gsplit]").forEach(b => b.onclick = () => { readEd(); const i = +b.dataset.gsplit, l = d.lignes[i];
      const parts = mags(l).map(m => Object.assign(JSON.parse(JSON.stringify(l)), {magasins:[m], magasin:m.nom}));
      d.lignes.splice(i, 1, ...parts); toast(parts.length + " lignes, une par point d'intervention"); redraw(); });
    document.querySelectorAll("[data-gday]").forEach(b => b.onclick = () => { readEd(); const l = d.lignes[+b.dataset.gday], iso = b.dataset.iso, z = l.zone || d.zone;
      if (l.dates.includes(iso)) { l.dates = l.dates.filter(x => x !== iso); l.maj = l.maj.filter(x => x !== iso); } else { l.dates.push(iso); l.dates.sort(); regleFerie(l, iso, z); } redraw(); });
    document.querySelectorAll("[data-gmajall]").forEach(c => c.onchange = () => { readEd(); const l = d.lignes[+c.dataset.gmajall], z = l.zone || d.zone; l.majFeries = c.checked;
      const fe = l.dates.filter(x => ferie(z, x) && !(l.fnt || []).includes(x)); l.maj = c.checked ? [...new Set(l.maj.concat(fe))] : l.maj.filter(x => !fe.includes(x)); redraw(); });
    document.querySelectorAll("[data-gfntall]").forEach(c => c.onchange = () => { readEd(); const l = d.lignes[+c.dataset.gfntall], z = l.zone || d.zone; l.fntOn = c.checked;
      if (!c.checked) { const fe = l.fnt || []; l.fnt = []; l.dates = l.dates.filter(x => !fe.includes(x)); } else l.dates.filter(x => ferie(z, x)).forEach(x => { l.maj = l.maj.filter(y => y !== x); if (!l.fnt.includes(x)) l.fnt.push(x); }); redraw(); });
    document.querySelectorAll("[data-gfnt]").forEach(b => b.onchange = () => { readEd(); const l = d.lignes[+b.dataset.gfnt], iso = b.dataset.iso; l.fnt = l.fnt || [];
      if (l.fnt.includes(iso)) l.fnt = l.fnt.filter(x => x !== iso); else { l.fnt.push(iso); l.maj = l.maj.filter(x => x !== iso); } redraw(); });
    document.querySelectorAll("[data-gext]").forEach(b => b.onclick = () => { readEd(); const i = +b.dataset.gext, x = tarif(d.lignes[i].tarif).extras[+b.dataset.k];
      d.lignes.splice(i + 1, 0, {type:"libre", designation:x.designation, qte:1, pu:x.pu}); toast("Ligne ajoutée : " + x.designation); redraw(); });
    document.querySelectorAll("[data-gmaj]").forEach(b => b.onchange = () => { readEd(); const l = d.lignes[+b.dataset.gmaj], iso = b.dataset.iso; l.maj = l.maj.includes(iso) ? l.maj.filter(x => x !== iso) : l.maj.concat(iso); l.fnt = (l.fnt || []).filter(x => !l.maj.includes(x)); redraw(); });
    document.querySelectorAll("[data-gx]").forEach(b => b.onclick = () => { readEd(); const l = d.lignes[+b.dataset.gx], iso = b.dataset.iso; l.dates = l.dates.filter(x => x !== iso); l.maj = l.maj.filter(x => x !== iso); l.fnt = (l.fnt || []).filter(x => x !== iso); redraw(); });
    document.querySelectorAll("[data-gpj]").forEach(box => box.onclick = e => { const c = e.target.closest(".chip"); if (c) c.setAttribute("aria-pressed", c.getAttribute("aria-pressed") !== "true"); });
    document.querySelectorAll("[data-gpadd]").forEach(b => b.onclick = () => { const box = b.closest(".gper"), du = box.querySelector('[data-gp="du"]').value, au = box.querySelector('[data-gp="au"]').value;
      const js = [...box.querySelectorAll('[data-gpj] .chip[aria-pressed="true"]')].map(c => +c.dataset.v);
      if (!du || !au || au < du) return toast("Indiquez le début et la fin de la période."); if (!js.length) return toast("Cochez au moins un jour de la semaine.");
      readEd(); const l = d.lignes[+b.dataset.gpadd], z = l.zone || d.zone; let n = 0;
      for (let x = du; x <= au; x = gAdd(x, 1)) { const wd = (new Date(x + "T12:00:00").getDay() + 6) % 7; const fp = tarif(l.tarif)?.feries_payes && l.fntOn !== false && ferie(z, x);
        if ((js.includes(wd) || fp) && !l.dates.includes(x)) { l.dates.push(x); regleFerie(l, x, z); n++; } if (n > 400) break; }
      l.dates.sort(); G.cal["c" + b.dataset.gpadd] = du.slice(0, 7); toast(n + " date" + (n > 1 ? "s" : "") + " ajoutée" + (n > 1 ? "s" : "")); redraw(); });
    document.querySelectorAll("[data-gpclr]").forEach(b => b.onclick = () => { readEd(); const l = d.lignes[+b.dataset.gpclr]; l.dates = []; l.maj = []; redraw(); });
  }
  document.querySelectorAll("[data-gcm]").forEach(b => b.onclick = () => { readEd(); const k = "c" + b.dataset.gcm, [y, m] = G.cal[k].split("-").map(Number), t = new Date(y, m - 1 + +b.dataset.dir, 1); G.cal[k] = t.getFullYear() + "-" + String(t.getMonth() + 1).padStart(2, "0"); redraw(); });
  if ($("#g-save")) $("#g-save").onclick = async () => { try { await saveEd(); toast((d.type === "facture" ? "Facture" : "Devis") + " enregistré" + (d.type === "facture" ? "e" : "")); redraw(); } catch(e) { toast(e.message); } };
  $("#g-pdf").onclick = async () => { const w = window.open("about:blank", "_blank"); try { const x = locked() ? G.ed : await saveEd(); w.location.href = "gestion_api.php?action=pdf&id=" + x.id; redraw(); } catch(e) { w && w.close(); toast(e.message); } };
  if ($("#g-mail")) $("#g-mail").onclick = async () => { try { const x = locked() ? G.ed : await saveEd(); const c = x.client || {};
      const m = await gapi("mail_modele", null, "id=" + x.id); G.mail = {to:c.emails || "", cc:c.cc || "", sujet:m.sujet, message:m.message};
      redraw(); $(".gmail").scrollIntoView({behavior:"smooth"}); } catch(e) { toast(e.message); } };
  if ($("#gm-x")) $("#gm-x").onclick = () => { G.mail = null; redraw(); };
  if ($("#gm-go")) $("#gm-go").onclick = async () => { const b = $("#gm-go"); if (b.dataset.confirm !== "1") { b.dataset.confirm = "1"; b.textContent = "Confirmer l'envoi à " + $("#gm-to").value; return; }
    b.disabled = true; try { await gapi("envoyer", {id:G.ed.id, to:$("#gm-to").value, cc:$("#gm-cc").value, sujet:$("#gm-suj").value, message:$("#gm-msg").value});
      toast("Envoyé"); G.mail = null; G.ed = (await gapi("doc", null, "id=" + G.ed.id)).doc; redraw(); } catch(e) { toast(e.message); b.disabled = false; } };
  if ($("#g-emit")) $("#g-emit").onclick = async () => { const b = $("#g-emit"); if (b.dataset.confirm !== "1") { b.dataset.confirm = "1"; b.textContent = "Confirmer : numéro définitif, plus de modification"; return; }
    try { await saveEd(); const r = await gapi("facture_emettre", {id:G.ed.id}); G.ed = (await gapi("doc", null, "id=" + G.ed.id)).doc; toast("Facture " + r.numero + " émise"); redraw(); } catch(e) { toast(e.message); } };
  if ($("#g-fac")) $("#g-fac").onclick = async () => { try { await saveEd(); const r = await gapi("facture_depuis_devis", {id:G.ed.id}); G.sub = "factures"; G.ed = (await gapi("doc", null, "id=" + r.id)).doc; G.mail = null; go("gestion"); } catch(e) { toast(e.message); } };
  if ($("#g-accm")) $("#g-accm").onclick = () => { G.accMail = true; redraw(); $(".gmail").scrollIntoView({behavior:"smooth"}); };
  if ($("#ga-x")) $("#ga-x").onclick = () => { G.accMail = false; redraw(); };
  if ($("#ga-go")) $("#ga-go").onclick = async () => { const f = $("#ga-f").files[0]; if (!f) return toast("Joignez l'e-mail du client.");
    if (f.size > 12e6) return toast("Fichier trop lourd (12 Mo au plus).");
    const b64 = await new Promise((ok, ko) => { const r = new FileReader(); r.onload = () => ok(r.result); r.onerror = ko; r.readAsDataURL(f); });
    try { const r = await gapi("accepter_email", {id:G.ed.id, le:$("#ga-le").value, de:$("#ga-de").value, nom:$("#ga-nom").value, fichier:b64, nom_fichier:f.name});
      G.ed = Object.assign(r.doc, {facture_id:G.ed.facture_id}); G.accMail = false; toast("Devis accepté : accord par e-mail enregistré"); redraw(); } catch(e) { toast(e.message); } };
  if ($("#g-aven")) $("#g-aven").onclick = async () => { try { const r = await gapi("avenant", {id:G.ed.id}); G.ed = (await gapi("doc", null, "id=" + r.id)).doc; G.mail = null;
    toast("Avenant " + G.ed.numero + " créé : cochez les jours supplémentaires"); redraw(); } catch(e) { toast(e.message); } };
  if ($("#g-dup")) $("#g-dup").onclick = async () => { try { const r = await gapi("doc_dupliquer", {id:G.ed.id}); G.ed = (await gapi("doc", null, "id=" + r.id)).doc; toast("Copie créée : " + G.ed.numero); redraw(); } catch(e) { toast(e.message); } };
  if ($("#g-del")) $("#g-del").onclick = async () => { const b = $("#g-del"); if (b.dataset.confirm !== "1") { b.dataset.confirm = "1"; b.textContent = "Confirmer la suppression"; return; }
    try { await gapi("doc_delete", {id:G.ed.id}); G.ed = null; toast("Supprimé"); go("gestion"); } catch(e) { toast(e.message); } };
  if ($("#g-st")) $("#g-st").onchange = async e => { try { await gapi("statut", {id:G.ed.id, statut:e.target.value}); G.ed.statut = e.target.value; toast("Statut mis à jour"); } catch(x) { toast(x.message); } };
  if (G.cli) bClientForm($("#g-cli-box"), async id => { G.ini = await gapi("init"); G.ed.client_id = id; const c = G.ini.clients.find(x => +x.id === id); if (c) { G.ed.tva = c.tva; G.ed.paiement = c.paiement; } G.cli = null; redraw(); });
}

/* ---------- Tableau de bord ---------- */
// Tableau de bord d'accueil : l'essentiel du jour, ce qui attend une action, et le fil des dernières activités.
const DB_ICO = {planning:'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>', contrat:'<path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5M10 13h6M10 17h4"/>',
  signe:'<path d="M4 20c3-1 4-6 7-6s2 4 5 4 3-3 4-4"/><path d="M15 4l5 5-8 8H7v-5z"/>', releve:'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>', offre:'<path d="M12 21s-7-5.3-7-11a7 7 0 0 1 14 0c0 5.7-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
  cr:'<path d="M5 12l4 4L19 6"/>', inscription:'<circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/>', fiche:'<circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/>',
  document:'<path d="M14 3H6v18h12V7z"/><path d="M14 3v4h4"/>', devis:'<path d="M5 12l4 4L19 6"/>', refus:'<path d="M6 6l12 12M18 6L6 18"/>'};
const dbIco = (t, sz = 18) => `<svg width="${sz}" height="${sz}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">${DB_ICO[t] || DB_ICO.document}</svg>`;
function dbIlya(s, min){ const d = min != null ? min : (Date.now() - new Date(s.replace(" ", "T")).getTime()) / 6e4; if (d < 1) return "à l'instant"; if (d < 60) return "il y a " + Math.round(d) + " min"; if (d < 1440) return "il y a " + Math.round(d / 60) + " h";
  return "le " + s.slice(8, 10) + "/" + s.slice(5, 7) + " à " + s.slice(11, 16); }
function dbJour(iso){ const t = gToday(); if (iso === t) return "Aujourd'hui"; if (iso === gAdd(t, -1)) return "Hier"; if (iso === gAdd(t, 1)) return "Demain";
  const d = new Date(iso + "T12:00:00"); return ["dimanche","lundi","mardi","mercredi","jeudi","vendredi","samedi"][d.getDay()] + " " + d.getDate() + " " + MOIS[d.getMonth()]; }
// Horloge de Paris dans le bandeau (mises à jour chaque minute).
const dbHeure = tz => new Intl.DateTimeFormat("fr-FR", {timeZone:tz, hour:"2-digit", minute:"2-digit"}).format(new Date());
const dbJourTz = tz => new Intl.DateTimeFormat("fr-FR", {timeZone:tz, weekday:"short", day:"numeric", month:"short"}).format(new Date());
setInterval(() => { document.querySelectorAll("[data-tz]").forEach(e => e.textContent = dbHeure(e.dataset.tz)); document.querySelectorAll("[data-tzd]").forEach(e => e.textContent = dbJourTz(e.dataset.tzd)); }, 20000);
// Premiers pas d'une nouvelle agence : liste des réglages à faire, chaque ligne mène au bon endroit.
function vDemarrage(P){
  const L = P.demarrage, f = L.filter(x => x.fait).length, pct = Math.round(f / L.length * 100);
  return `<div class="card pp-card"><div class="pp-h"><div><h2>Premiers pas</h2><div class="muted">${f} sur ${L.length} réglé${f > 1 ? "s" : ""}. ${P.code_appli ? `Code de l'appli à donner à votre équipe : <b>${esc(P.code_appli)}</b>.` : ""}</div></div>
      <button class="ghost sm" id="pp-x">Masquer</button></div><div class="pp-bar"><i style="width:${pct}%"></i></div>
    <div class="pp-list">${L.map(x => `<button type="button" class="pp-i${x.fait ? " ok" : ""}" ${x.fait ? "disabled" : `data-ppgo="${x.cible}"`}><span class="pp-ck">${x.fait ? "✓" : ""}</span>${esc(x.texte)}</button>`).join("")}</div></div>`;
}
function vPilotage(){
  const P = G.pil; if (!P) return ""; const k = P.kpi, now = new Date();
  const tile = (n, lab, sub, cible, ton, ico, tk) => `<button type="button" class="db-t ${ton || ""}" data-dbgo="${cible}" data-dbk="${tk || ""}" data-dbt="${esc(lab)}"><span class="db-ic">${dbIco(ico, 20)}</span><b>${n}</b><span class="db-l">${lab}</span>${sub ? `<span class="db-s">${sub}</span>` : ""}</button>`;
  const J = {}; P.jours.forEach(j => (J[j.date] = J[j.date] || []).push(j));
  const fin = j => new Date(j.date + "T" + j.fin) < now;
  const F = {}; P.fil.forEach(f => (F[f.le.slice(0, 10)] = F[f.le.slice(0, 10)] || []).push(f));
  const d = new Date(); const dateTxt = ["dimanche","lundi","mardi","mercredi","jeudi","vendredi","samedi"][d.getDay()] + " " + d.getDate() + " " + MOIS[d.getMonth()] + " " + d.getFullYear();
  return `<div class="db-hero"><div><div class="db-k">Tableau de bord · ${dateTxt}</div><div class="db-h">Bonjour ${esc(P.moi)}</div>
      <div class="db-sub">${k.aujourdhui ? `${k.aujourdhui} animation${k.aujourdhui > 1 ? "s" : ""} aujourd'hui` : "Pas d'animation aujourd'hui"}${k.a_pourvoir ? ` · <b>${k.a_pourvoir} créneau${k.a_pourvoir > 1 ? "x" : ""} à pourvoir</b>` : " · tous les créneaux sont pourvus"}${k.dpae ? ` · <b class="db-red">${k.dpae} DPAE urgente${k.dpae > 1 ? "s" : ""}</b>` : ""}</div></div>
      <div class="db-clocks">${[["Paris", "Europe/Paris"]].map(([l, tz]) => `<div class="db-clk"><span class="db-ct" data-tz="${tz}">${dbHeure(tz)}</span><span class="db-cl">${l}</span><span class="db-cd" data-tzd="${tz}">${dbJourTz(tz)}</span></div>`).join("")}</div>
</div>
    ${P.demarrage ? vDemarrage(P) : ""}
    <div class="db-grid">
      ${k.poste && k.poste.total ? tile(k.poste.en_poste + "/" + k.poste.total, "en poste aujourd'hui", [k.poste.retard ? k.poste.retard + " en retard" : "", k.poste.non_signale ? k.poste.non_signale + " non signalé" + (k.poste.non_signale > 1 ? "s" : "") : "", k.poste.absent ? k.poste.absent + " absent" + (k.poste.absent > 1 ? "s" : "") : ""].filter(Boolean).join(" · ") || "tout le monde est là", "poste", k.poste.absent || k.poste.non_signale ? "bad" : k.poste.retard ? "warn" : "ok", "signe") : tile(k.aujourdhui, "animation" + (k.aujourdhui > 1 ? "s" : "") + " aujourd'hui", k.demain + " demain", "poste", "", "planning")}
      ${tile(k.a_pourvoir, "créneau" + (k.a_pourvoir > 1 ? "x" : "") + " à pourvoir", k.ops_a_booker + " opération" + (k.ops_a_booker > 1 ? "s" : "") + " à booker", "operations", k.a_pourvoir ? "warn" : "ok", "offre", "operations")}
      ${tile(k.devis_attente, "devis à valider", "en attente de réponse du client", "devis", "", "devis", "devis")}
      ${tile(k.contrats_a_signer, "contrat" + (k.contrats_a_signer > 1 ? "s" : "") + " à signer", k.sans_contrat ? k.sans_contrat + " à envoyer" : "aucun à envoyer", "contrats", k.sans_contrat ? "warn" : "", "contrat", "contrats")}
      ${tile(k.dpae, "DPAE à faire", "1re journée dans 3 jours ou moins", "contrats", k.dpae ? "bad" : "ok", "signe", "dpae")}
      ${tile(k.releves_a_signer, "relevé" + (k.releves_a_signer > 1 ? "s" : "") + " d'heures à signer", "envoyés, en attente", "releves", "", "releve", "releves")}
      ${k.presence ? tile(k.presence, "marque" + (k.presence > 1 ? "s" : "") + " à prévenir", "retard, absence ou remplacement aujourd'hui", "poste", "bad", "offre") : ""}
      ${k.ndf ? tile(k.ndf, "note" + (k.ndf > 1 ? "s" : "") + " de frais à valider", "photo du ticket jointe", "ndf", "warn", "document") : ""}
      ${k.cdii ? tile(k.cdii, "CDII à proposer", "500 h atteintes en CIDD, 2 mois pour proposer", "cdii", "bad", "contrat", "cdii") : k.cdii_bientot ? tile(k.cdii_bientot, "proche" + (k.cdii_bientot > 1 ? "s" : "") + " des 500 h", "en CIDD sur 12 mois", "cdii", "warn", "contrat", "cdii") : ""}
      ${k.fins ? tile(k.fins, "attestation" + (k.fins > 1 ? "s" : "") + " France Travail", "contrats terminés, à faire sur France Travail", "contrats", "bad", "contrat", "fins") : ""}
      ${k.docs_exp ? tile(k.docs_exp, "pièce" + (k.docs_exp > 1 ? "s" : "") + " d'identité à renouveler", "expirée ou dans moins d'un mois", "documents", "warn", "document", "documents") : ""}
      ${k.antichambre ? tile(k.antichambre, "inscription" + (k.antichambre > 1 ? "s" : "") + " à valider", "dans l'antichambre", "animatrices", "warn", "inscription") : ""}
      ${tile(k.inscrits, "inscrit(e)s dans l'appli", k.nouveaux ? "+" + k.nouveaux + " ces 30 jours" : k.equipe + " dans la liste", "animatrices", "", "inscription")}
      ${tile(k.positionnes, "candidature" + (k.positionnes > 1 ? "s" : "") + " à traiter", "sur les offres de mission", "planning", k.positionnes ? "warn" : "", "offre", "candidatures")}
      ${tile(k.cr_semaine, "compte" + (k.cr_semaine > 1 ? "s" : "") + " rendu" + (k.cr_semaine > 1 ? "s" : ""), "reçus cette semaine", "rapports", "", "cr")}
      ${k.mc ? tile(k.mc, "visite" + (k.mc > 1 ? "s" : "") + " merchandising", "à relire et valider", "rapports-mc", "warn", "cr", "mc") : ""}
      ${k.evts ? tile(k.evts, "compte" + (k.evts > 1 ? "s" : "") + " rendu" + (k.evts > 1 ? "s" : "") + " d'événement", "à relire et valider", "rapports-evt", "warn", "cr", "evts") : ""}
    </div>
    <div class="db-cols">
      <div class="card db-card"><h2>Aujourd'hui et demain</h2>
        ${[P.aujourdhui, P.demain].map(dt => `<div class="db-day">${dbJour(dt)}</div>${(J[dt] || []).map(j => `<div class="db-m${j.devis_id ? " click" : ""}" ${j.devis_id ? `data-dbop="${j.devis_id}"` : ""}><span class="db-hr">${esc(j.debut)}<br><span>${esc(j.fin)}</span></span><div><b>${esc(j.nom)}</b><div class="hint">${esc(j.magasin || "point d'intervention à préciser")}${j.client ? " · " + esc(j.client) : ""}</div></div>
          ${dt === P.aujourdhui ? (+j.cr ? `<span class="pill p-ok">CR reçu</span>` : fin(j) ? `<span class="pill p-bad">CR attendu</span>` : "") : ""}</div>`).join("") || `<div class="hint db-none">Aucune animation.</div>`}`).join("")}
        ${P.ops.length ? `<div class="db-day">Opérations à booker</div>${P.ops.map(o => `<div class="db-m click" data-dbop="${o.id}"><span class="db-hr db-n">${o.n}</span><div><b>${esc(o.nom)}</b><div class="hint">${o.n} créneau${o.n > 1 ? "x" : ""} sans personne</div></div></div>`).join("")}` : ""}</div>
      <div class="card db-card"><h2>Dernières activités</h2>
        ${Object.keys(F).length ? Object.keys(F).map(dt => `<div class="db-day">${dbJour(dt)}</div>${F[dt].map(f => `<div class="db-f${f.o ? " click" : ""}" ${f.o ? `data-dbo='${esc(JSON.stringify(f.o))}'` : ""}><span class="db-fi t-${f.type}">${dbIco(f.type, 16)}</span><div><div><b>${esc(f.qui)}</b> ${esc(f.quoi)}${f.sur ? ` <span class="db-sur">· ${esc(f.sur)}</span>` : ""}</div><div class="hint">${dbIlya(f.le, f.min)}</div></div></div>`).join("")}`).join("")
          : `<div class="hint">Rien ces trois dernières semaines.</div>`}</div>
    </div>
    <div class="db-sec">Facturation</div>`;
}
/* À traiter : un clic mène à l'élément ; plusieurs éléments, la liste s'affiche en tête de l'onglet concerné. */
const FOC = { L:[], titre:"", tab:"", sub:"" };
async function openTodo(o){
  if (!o) return; FOC.L = FOC.L || [];
  try {
    if (o.k === "doc") { G.ed = (await gapi("doc", null, "id=" + o.id)).doc; G.sub = G.ed.type === "facture" ? "factures" : "devis"; G.mail = null; return go("gestion"); }
    if (o.k === "contrat") { CT.vue = ""; CT.sel = {a:o.a, d:o.d}; G.sub = "contrats"; G.ed = null; return go("gestion"); }
    if (o.k === "releve") { RL.aid = o.a; RL.open = o.m; if (o.m) RL.mois = o.m; G.sub = "releves"; G.ed = null; return go("gestion"); }
    if (o.k === "planning") { if (typeof PL !== "undefined") PL.id = o.d; G.sub = "planning"; G.ed = null; return go("gestion"); }
    if (o.k === "cdii") { G.sub = "contrats"; G.ed = null; CT.vue = "cdii"; CT.sel = null; CT.C = null; return go("gestion"); }
    if (o.k === "fiche") return showFiche(o.a);
    if (o.k === "cr") { A.crt = "anim"; await go("rapports"); return showDetail(o.id); }
    if (o.k === "mc") { A.crt = "mc"; MC.sel = o.id; await go("rapports"); return scrollTo(0, 0); }
    if (o.k === "evt") { A.crt = "evt"; EV.sel = o.id; await go("rapports"); return scrollTo(0, 0); }
  } catch(e) { toast(e.message); }
}
function focusInject(){
  if (!FOC.L || !FOC.L.length) return;
  const ici = FOC.tab === "gestion" ? A.tab === "gestion" && G.sub === FOC.sub && !G.ed : A.tab === FOC.tab; if (!ici) return;
  const v = $("#view"), c = v && v.querySelector(".card"); if (!c || $("#foc")) return;
  c.insertAdjacentHTML("beforebegin", `<div class="card accent" id="foc"><div style="display:flex;justify-content:space-between;align-items:center;gap:10px"><h2>À traiter · ${esc(FOC.titre)} (${FOC.L.length})</h2><button class="ghost sm" id="foc-x">Fermer</button></div>
    <div class="foc-l">${FOC.L.map((x, i) => `<button type="button" class="foc-i" data-foc="${i}"><b>${esc(x.t)}</b><span class="hint">${esc(x.s || "")}</span><span class="foc-go">Ouvrir ›</span></button>`).join("")}</div></div>`);
  $("#foc-x").onclick = () => { FOC.L = []; $("#foc").remove(); };
  document.querySelectorAll("[data-foc]").forEach(b => b.onclick = () => openTodo(FOC.L[+b.dataset.foc].o));
}
function vTableau(){
  const T = G.tab, k = T.kpi, row = (d, extra) => `<tr class="click" data-gopen="${d.id}"><td><b>${esc(d.numero || "brouillon")}</b></td><td>${esc(d.client_nom)}</td><td>${esc(d.objet)}</td><td class="num">${gEur(d.total_ht)}</td><td>${extra}</td></tr>`;
  const tb = (titre, L, extra, vide) => `<div class="card tablebox"><h2>${titre} (${L.length})</h2>${L.length ? `<div class="tablewrap"><table><tr><th>N°</th><th>Client</th><th>Objet</th><th class="num">HT</th><th></th></tr>${L.map(d => row(d, extra(d))).join("")}</table></div>` : `<div class="empty">${vide}</div>`}</div>`;
  const dd = s => s ? fmtD(s.slice(0, 10)) : "";
  return vPilotage() + `<div class="kpis3">
      <div class="kpi"><b>${gEur(k.attente_ht)}</b><span>${k.attente_n} devis en attente de réponse</span></div>
      <div class="kpi"><b>${gEur(k.afacturer_ht)}</b><span>${k.afacturer_n} devis acceptés à facturer</span></div>
      <div class="kpi"><b>${gEur(k.encours_ttc)}</b><span>factures à encaisser (TTC)${k.retard_ttc ? ` · <b style="color:#A33">${gEur(k.retard_ttc)} en retard</b>` : ""}</span></div>
      <div class="kpi"><b>${gEur(k.mois_ht)}</b><span>facturé ce mois (HT)</span></div><div class="kpi"><b>${gEur(k.annee_ht)}</b><span>facturé depuis le 1er janvier (HT)</span></div></div>
    ${T.enligne.length ? `<div class="card"><h2>Dernières réponses des marques</h2>${T.enligne.map(d => `<div class="click gev" data-gopen="${d.id}"><span class="pill ${d.statut === "accepte" ? "p-ok" : "p-bad"}">${d.statut === "accepte" ? "Accepté" : "Refusé"}</span> <b>${esc(d.numero)}</b> · ${esc(d.client_nom)} · ${gEur(d.total_ht)} HT <span class="hint">· ${esc(dd(d.accepte_le))} · ${esc(d.accepte_par)}</span></div>`).join("")}</div>` : ""}
    ${tb("Acceptés, à facturer", T.afacturer, d => `<span class="pill p-ok">accepté</span>${d.accepte_le ? `<div class="hint">en ligne le ${esc(dd(d.accepte_le))}</div>` : ""}`, "Aucun devis accepté en attente de facture.")}
    ${tb("Envoyés, en attente de réponse", T.attente, d => `<span class="pill p-warn">envoyé le ${esc(dd(d.envoye_le))}</span>${d.validite && d.validite < new Date().toISOString().slice(0, 10) ? `<div class="hint" style="color:#A33">expiré le ${esc(fmtD(d.validite))}</div>` : d.validite ? `<div class="hint">valable jusqu'au ${esc(fmtD(d.validite))}</div>` : ""}`, "Aucun devis en attente.")}
    ${tb("Factures à encaisser", T.encours, d => `<span class="pill ${d.retard ? "p-bad" : "p-warn"}">${d.retard ? d.retard + " j de retard" : "échéance " + esc(fmtD(d.echeance))}</span><div class="hint">${gEur(d.total_ttc)} TTC</div>`, "Aucune facture en attente de paiement.")}`;
}
function bTableau(){
  if ($("#pp-x")) $("#pp-x").onclick = async () => { try { await gapi("pp_masquer", {}); G.pil.demarrage = null; redraw(); } catch(e) { toast(e.message); } };
  document.querySelectorAll("[data-ppgo]").forEach(b => b.onclick = () => { const k = b.dataset.ppgo;
    if (k === "moncompte") return myAccount(); if (k === "magasins" || k === "animatrices") return go(k); G.sub = k; G.ed = null; go("gestion"); });
  document.querySelectorAll("[data-dbo]").forEach(b => b.onclick = () => { try { openTodo(JSON.parse(b.dataset.dbo)); } catch(e) {} });
  document.querySelectorAll("[data-dbgo]").forEach(b => b.onclick = () => { const k = b.dataset.dbgo, L = ((G.pil || {}).todo || {})[b.dataset.dbk] || null;
    FOC.L = []; if (L && L.length === 1) return openTodo(L[0].o);
    if (L && L.length > 1) Object.assign(FOC, {titre:b.dataset.dbt, L, tab:["rapports", "animatrices", "documents"].includes(k) ? k : "gestion", sub:["rapports", "animatrices", "documents"].includes(k) ? "" : k});
    if (k === "rapports-mc") { A.crt = "mc"; MC.f.statut = "avalider"; MC.sel = null; FOC.tab = "rapports"; return go("rapports"); }
    if (k === "rapports-evt") { A.crt = "evt"; EV.f.statut = "avalider"; EV.sel = null; FOC.tab = "rapports"; return go("rapports"); }
    if (k === "rapports") A.crt = "anim";
    if (k === "rapports" || k === "animatrices" || k === "documents") return go(k);
    if (k === "cdii") { G.sub = "contrats"; G.ed = null; CT.vue = "cdii"; CT.sel = null; CT.C = null; go("gestion"); return; }
    if (k === "contrats") { CT.vue = ""; CT.sel = null; CT.C = null; } if (k === "releves") RL.aid = 0;
    if (k === "devis-new") { G.sub = "devis"; G.ed = null; go("gestion").then(() => $("#g-new") && $("#g-new").click()); return; }
    G.sub = k; G.ed = null; go("gestion"); });
  document.querySelectorAll("[data-dbop]").forEach(b => b.onclick = () => { if (typeof PL !== "undefined") PL.id = +b.dataset.dbop; G.sub = "planning"; go("gestion"); });
  document.querySelectorAll("[data-gopen]").forEach(tr => tr.onclick = async () => { try { G.ed = (await gapi("doc", null, "id=" + tr.dataset.gopen)).doc; G.sub = G.ed.type === "facture" ? "factures" : "devis"; G.mail = null; go("gestion"); } catch(e) { toast(e.message); } }); }

/* ---------- Clients ---------- */
function clientForm(c){
  const I = G.ini;
  return `<div class="gcf" data-cid="${c.id || 0}"><div class="row3"><label>Raison sociale<input data-c="raison" value="${esc(c.raison || "")}" placeholder="Ex. SOCIÉTÉ CLIENTE SAS"></label><label>À l'attention de<input data-c="contact" value="${esc(c.contact || "")}" placeholder="Ex. Claire MARTIN"></label><label>N° TVA intracommunautaire<input data-c="tva_intra" value="${esc(c.tva_intra || "")}"></label></div>
    <div class="row3"><label>Adresse<input data-c="adresse" value="${esc(c.adresse || "")}"></label><label>Code postal<input data-c="cp" value="${esc(c.cp || "")}"></label><label>Ville<input data-c="ville" value="${esc(c.ville || "")}"></label></div>
    <div class="row3"><label>Pays<input data-c="pays" value="${esc(c.pays || "France")}"></label><label>E-mail(s) d'envoi<input data-c="emails" value="${esc(c.emails || "")}" placeholder="Séparez par une virgule"></label><label>Copie (Cc)<input data-c="cc" value="${esc(c.cc || "")}"></label></div>
    <div class="row3"><label>TVA par défaut<select data-c="tva">${Object.entries(I.tva).map(([k, l]) => `<option value="${k}" ${String(+(c.tva || 20)) === String(+k) ? "selected" : ""}>${l}</option>`).join("")}</select></label>
      <label>Paiement par défaut<select data-c="paiement">${Object.entries(I.paiements).map(([k, l]) => `<option value="${k}" ${(c.paiement || "30j") === k ? "selected" : ""}>${l}</option>`).join("")}</select></label>
      <label>Compte bancaire sur ses documents<select data-c="compte"><option value="" ${c.compte !== "2" ? "selected" : ""}>${esc(I.reglages.banque)} (principal)</option><option value="2" ${c.compte === "2" ? "selected" : ""}>${esc(I.reglages.banque2 || "Second compte")}</option></select></label></div>
    <div class="row3"><label>Tarif par défaut<select data-c="tarif"><option value="">Aucun</option>${(I.reglages.tarifs || []).map(t => `<option value="${esc(t.id)}" ${c.tarif === t.id ? "selected" : ""}>${esc(t.nom)} · ${gEur(t.prix)}</option>`).join("")}</select></label><span></span><span></span></div>
    <div class="row3"><label>Prévenir la marque des problèmes de présence<select data-c="presence_mode"><option value="incident" ${(c.presence_mode || "incident") === "incident" ? "selected" : ""}>Oui, en cas de retard, absence ou remplacement</option><option value="aucun" ${c.presence_mode === "aucun" ? "selected" : ""}>Non, jamais</option></select></label>
      <label>Adresse(s) pour ces e-mails <span class="hint">· vide : celles du client</span><input data-c="presence_emails" value="${esc(c.presence_emails || "")}" placeholder="Ex. chef de marque, responsable terrain"></label>
      <label>Envoi<select data-c="presence_auto"><option value="0" ${+c.presence_auto ? "" : "selected"}>L'administration relit et envoie</option><option value="1" ${+c.presence_auto ? "selected" : ""}>Automatique, 1 h après le début</option></select></label></div>
    <label>Notes<textarea data-c="notes" rows="2">${esc(c.notes || "")}</textarea></label>
    <div class="btns"><button class="primary" data-csave>${c.id ? "Enregistrer" : "Créer le client"}</button>${c.id ? `<button class="ghost danger" data-cdel>Retirer de la liste</button>` : ""}</div></div>`;
}
function bClientForm(box, done){
  if (!box) return; const f = box.querySelector(".gcf");
  f.querySelector("[data-csave]").onclick = async () => { const body = {id:+f.dataset.cid}; f.querySelectorAll("[data-c]").forEach(i => body[i.dataset.c] = i.value.trim());
    try { const r = await gapi("client_save", body); toast("Client enregistré"); await done(r.id); } catch(e) { toast(e.message); } };
  const del = f.querySelector("[data-cdel]"); if (del) del.onclick = async () => { if (del.dataset.confirm !== "1") { del.dataset.confirm = "1"; del.textContent = "Confirmer"; return; }
    try { await gapi("client_delete", {id:+f.dataset.cid}); G.ini = await gapi("init"); G.cedit = null; go("gestion"); } catch(e) { toast(e.message); } };
}
function vClients(){
  const L = G.ini.clients;
  return `${vImport("clients")}<div class="card"><h2>Nouveau client</h2><div id="g-cnew">${clientForm({})}</div></div>
    <div class="card tablebox"><h2>Clients (${L.length})</h2>${L.length ? `<div class="tablewrap"><table><tr><th>Client</th><th>Adresse</th><th>E-mail</th><th>TVA · paiement</th><th></th></tr>
    ${L.map(c => +G.cedit === +c.id ? `<tr><td colspan="5"><div id="g-cedit">${clientForm(c)}</div></td></tr>` : `<tr><td><b>${esc(c.raison)}</b>${c.contact ? `<div class="hint">${esc(c.contact)}</div>` : ""}</td><td>${esc(c.adresse)}<br>${esc(c.cp)} ${esc(c.ville)}</td><td>${esc(c.emails)}</td>
      <td>${esc(G.ini.tva[String(+c.tva)] || G.ini.tva[c.tva] || c.tva)}<br><span class="hint">${esc(G.ini.paiements[c.paiement] || "")}${c.compte === "2" ? " · " + esc(G.ini.reglages.banque2) : ""}</span></td><td><button class="ghost" data-cedit="${c.id}">Modifier</button></td></tr>`).join("")}</table></div>` : `<div class="empty">Aucun client.</div>`}</div>`;
}
function bClients(){
  bClientForm($("#g-cnew"), async () => { G.ini = await gapi("init"); go("gestion"); });
  bImport("clients", async () => { G.ini = await gapi("init"); go("gestion"); });
  bClientForm($("#g-cedit"), async () => { G.ini = await gapi("init"); G.cedit = null; go("gestion"); });
  document.querySelectorAll("[data-cedit]").forEach(b => b.onclick = () => { G.cedit = +b.dataset.cedit; go("gestion"); });
}

/* ---------- Réglages ---------- */
// Modèle de contrat de travail : texte de l'agence (CIDD animation ou CDDU accueil), jamais fourni par Amaya.
function cardModele(r, k){
  const hot = k === "hot", nm = hot ? "CDDU · Accueil & Hôtessariat" : "CIDD · Animation commerciale", court = hot ? "CDDU" : "CIDD";
  return `<div class="card"><h2>Contrat ${nm}</h2>
    <div class="muted">${esc(PRODUIT)} ne fournit <b>aucun modèle de contrat</b> : le texte est celui de votre agence, rédigé ou validé par votre avocat et adapté à votre convention collective, sous votre responsabilité. Collez-le ci-dessous (depuis Word par exemple) : Amaya y ajoute les parties, le tableau des journées et des horaires, les frais, et la signature dans l'appli. Tant que le texte est vide ou que la case n'est pas cochée, aucun contrat ${court} ne peut être envoyé.</div>
    <div class="row3"><label>Taux horaire brut par défaut (€)<input data-r="${hot ? "hot_taux" : "ct_taux"}" value="${esc(r[hot ? "hot_taux" : "ct_taux"])}"></label><label style="grid-column:span 2">Convention collective applicable<input data-r="${k}_convention" value="${esc(r[k + "_convention"] || "")}" placeholder="Intitulé complet et IDCC"></label></div>
    <label>Texte de votre contrat ${court}<textarea data-r="${k}_modele" rows="14" placeholder="Collez ici le texte de votre contrat. Une ligne « Article 1 - Objet » (ou « ## Objet ») commence un article ; une ligne commençant par « - » fait une puce.">${esc(r[k + "_modele"] || "")}</textarea></label>
    <details><summary>Champs remplis automatiquement dans votre texte</summary><div class="hint" style="line-height:1.7">
      {salarie} / {Salarie} : « la Salariée », « le Salarié »… · {e} : accord (engagé{e}) · {emploi} : poste, accordé · {coef} : coefficient · {client} · {operation} · {magasins} : enseignes · {lieux} : adresses · {duree} : dates et heures de début et de fin · {essai} : jours d'essai · {taux} : taux horaire brut · {repas} · {km} · {convention} · {societe} · {appli} · {retraite} · {caisse} · {urssaf} · {dpae} : date de la DPAE<br>
      Seuls sur leur ligne : {horaires} insère le tableau des journées et des horaires ; {frais} insère l'article des frais de déplacement.</div></details>
    <label class="ck"><input type="checkbox" data-r="${k}_valide" value="1" ${r[k + "_valide"] ? "checked" : ""}><span>Ce texte est le contrat de notre agence, validé par notre avocat : l'envoi des contrats ${court} est autorisé</span></label></div>`;
}
// Règles de paye : taux de la convention collective de l'agence (ou de son accord), par pôle. Vide = valeur par défaut affichée en gris.
const RG_DEF = {hs_seuil:"35", hs_t1:"25", hs_t1_max:"43", hs_t2:"50", dim:"100", ferie:"100", nuit:"0", nuit_debut:"21:00", nuit_fin:"06:00", zti:"100"};
function cardRegles(r){
  const P = POLES_ACTIFS(), lab = {anim:"Animation (CIDD)", hot:"Accueil (CDDU)"};
  const fi = (p, k, l, t) => `<label>${l}<input data-r="rg_${p}_${k}" ${t ? `type="${t}"` : `inputmode="decimal"`} value="${esc(r["rg_" + p + "_" + k] || "")}" placeholder="${RG_DEF[k]}"></label>`;
  return `<div class="card"><h2>Règles de paye</h2>
    <div class="muted">Taux de <b>votre convention collective</b> ou de votre accord d'entreprise, sous votre responsabilité : faites-les valider. Ils servent à la préparation de paye et au tableau des journées des contrats. Vide = valeur grisée. Deux planchers légaux s'appliquent toujours : 1er mai travaillé +100 %, et soirée en zone touristique internationale (ZTI) de 21 h à minuit +100 %, avec repos compensateur équivalent et retour à domicile à la charge de l'employeur.</div>
    ${P.map(p => `<h3 style="margin:14px 0 6px">${lab[p]}</h3>
      <div class="row3">${fi(p, "hs_seuil", "Heures sup au-delà de (h / semaine)")}${fi(p, "hs_t1", "1er taux (%)")}${fi(p, "hs_t1_max", "1er taux jusqu'à (h / semaine)")}</div>
      <div class="row3">${fi(p, "hs_t2", "2e taux, au-delà (%)")}${fi(p, "dim", "Dimanche (%)")}${fi(p, "ferie", "Jour férié travaillé (%)")}</div>
      <div class="row3">${fi(p, "nuit", "Heures de nuit (%)")}${fi(p, "nuit_debut", "Nuit : de", "time")}${fi(p, "nuit_fin", "à", "time")}</div>
      <div class="row3">${fi(p, "zti", "Soirée ZTI 21 h - 24 h (%, 100 minimum)")}<label class="ck" style="align-self:end"><input type="checkbox" data-r="rg_${p}_cumul" value="1" ${r["rg_" + p + "_cumul"] ? "checked" : ""}><span>Cumuler les majorations (sinon, la plus favorable s'applique)</span></label><span></span></div>`).join("")}
    <div class="hint">Les heures sup sont comptées par semaine civile (lundi au dimanche), toutes opérations confondues. Les points d'intervention en ZTI se cochent dans Points d'intervention.</div></div>`;
}
function vReglages(){
  const r = G.ini.reglages, f = (k, l, rows) => rows ? `<label>${l}<textarea data-r="${k}" rows="${rows}">${esc(r[k])}</textarea></label>` : `<label>${l}<input data-r="${k}" value="${esc(r[k])}"></label>`;
  const im = G.ini.images || {}, imgs = [["logo","Logo (fond clair : documents imprimés, e-mails)"],["logo_blanc","Logo version claire (bandeau de l'appli)"]];
  const ap = G.ini.apparence || {themes:{}}, th = ap.themes;
  return `<div class="card"><h2>Apparence</h2><div class="muted">Thème et couleur principale de votre conciergerie : appli de l'équipe, administration, pages des voyageurs et des propriétaires.</div>
    <div class="ap-themes">${Object.entries(th).map(([k, t]) => `<label class="ap-t${ap.theme === k ? " on" : ""}"><input type="radio" name="ap-theme" value="${k}" ${ap.theme === k ? "checked" : ""}>
      <span class="ap-sw" style="background:linear-gradient(in oklch 120deg,${t.defaut},${t.pop})"></span><span class="ap-bg" style="background:${t.bg};border-color:${t.line}"><i style="background:${t.ink}"></i><i style="background:${t.defaut}"></i><i style="background:${t.pop}"></i></span><b>${esc(t.nom)}</b></label>`).join("")}</div>
    <div class="row3"><label>Couleur principale<input type="color" id="ap-c" value="${esc(ap.couleur || "#43515C")}" style="height:46px;padding:4px"></label>
      <div class="hint" style="align-self:end">Conseil : gardez la couleur proposée par le thème, ou celle de votre logo.</div><div style="align-self:end"><button class="primary" id="ap-save">Appliquer</button></div></div></div>
    <div class="card"><h2>Logo</h2><div class="muted">PNG de préférence, fond transparent. Sans logo, celui de ${esc(PRODUIT)} s'affiche.</div><div class="row3">
    ${imgs.map(([k, l]) => `<label>${l}<span class="hint">${im[k] ? "✓ en place" : "aucun"}</span><input type="file" accept="image/png,image/jpeg,image/webp" data-img="${k}">${im[k] ? `<button type="button" class="ghost" data-imgdel="${k}">Retirer</button>` : ""}</label>`).join("")}</div></div>
    <div class="card"><h2>Société</h2><div class="row3">${f("societe", "Raison sociale")}${f("contact", "Responsable")}<span></span></div>
    <div class="row3">${f("adresse", "Adresse", 2)}${f("telephone", "Téléphone")}${f("email", "E-mail")}</div><div class="row3">${f("site", "Site")}${f("siret", "ICE")}<span></span></div><div class="row3">${f("banque", "Banque")}${f("iban", "RIB (règlements des propriétaires)")}<span></span></div>${f("legal", "Mentions légales (pied de page)", 2)}</div>
    <div class="btns"><button class="primary" id="g-rsave">Enregistrer les réglages</button></div>`;
}
function bReglages(){
  const readT = () => [...document.querySelectorAll("[data-tk]")].map(tr => { const o = {}; tr.querySelectorAll("[data-t]").forEach(i => o[i.dataset.t] = i.type === "checkbox" ? i.checked : i.value);
    o.prix = gNum(o.prix); o.pause = +o.pause || 0; o.extras = String(o.extras || "").split("\n").map(x => x.split(";")).filter(x => x[0].trim()).map(x => ({designation:x[0].trim(), pu:gNum(x[1])})); return o; });
  const rv = i => i.type === "checkbox" ? (i.checked ? "1" : "") : i.value;
  const keep = () => document.querySelectorAll("[data-r]").forEach(i => G.ini.reglages[i.dataset.r] = rv(i));
  document.querySelectorAll("[data-pole]").forEach(c => c.onchange = () => { const a = [...document.querySelectorAll("[data-pole]:checked")].map(x => x.dataset.pole); if (!a.length) { c.checked = true; return toast("Gardez au moins un pôle."); } $("#g-poles").value = a.join(","); });
  if ($("#g-tadd")) $("#g-tadd").onclick = () => { keep(); G.ini.reglages.tarifs = readT().concat([{id:"", nom:"Nouveau tarif", prix:185, debut:"10:00", fin:"18:00", pause:60, poste:"Animation", feries_payes:false, extras:[]}]); go("gestion"); };
  document.querySelectorAll("[data-tdel]").forEach(b => b.onclick = () => { keep(); const L = readT(); L.splice(+b.dataset.tdel, 1); G.ini.reglages.tarifs = L; go("gestion"); });
  document.querySelectorAll("[name=ap-theme]").forEach(r => r.onchange = () => { const t = (G.ini.apparence.themes || {})[r.value]; if (t) $("#ap-c").value = t.defaut; document.querySelectorAll(".ap-t").forEach(x => x.classList.toggle("on", x.contains(r))); });
  $("#ap-save").onclick = async () => { const th = (document.querySelector("[name=ap-theme]:checked") || {}).value || "orvia";
    try { await gapi("apparence", {theme:th, couleur:$("#ap-c").value}); toast("Apparence appliquée"); setTimeout(() => location.reload(), 400); } catch(e) { toast(e.message); } };
  const imgEnv = async (type, data) => { try { await gapi("identite_image", {type, data}); G.ini.images = Object.assign(G.ini.images || {}, {[type]: !!data}); toast(data ? "Image enregistrée" : "Image retirée"); keep(); go("gestion"); } catch(e) { toast(e.message); } };
  document.querySelectorAll("[data-img]").forEach(i => i.onchange = () => { const f = i.files[0]; if (!f) return; const rd = new FileReader(); rd.onload = () => imgEnv(i.dataset.img, rd.result); rd.readAsDataURL(f); });
  document.querySelectorAll("[data-imgdel]").forEach(b => b.onclick = () => imgEnv(b.dataset.imgdel, ""));
  $("#g-rsave").onclick = async () => { const body = document.querySelector("[data-tk]") ? {tarifs:readT()} : {}; document.querySelectorAll("[data-r]").forEach(i => body[i.dataset.r] = rv(i));
    try { const r = await gapi("reglages_save", body); G.ini.reglages = r.reglages; toast("Réglages enregistrés"); } catch(e) { toast(e.message); } };
}
