// Import de clients ou de points d'intervention (Excel, CSV ou copier-coller) : aperçu, puis import ; les doublons sont ignorés.
function vImport(type){
  const T = {clients:["Importer des clients", "Raison sociale, contact, e-mail, adresse, code postal, ville"], magasins:["Importer des points d'intervention", "Nom du point d'intervention, enseigne, adresse, code postal, ville, zone"]}[type];
  return `<details class="card imp-card"><summary><b>${T[0]}</b> <span class="hint">· depuis Excel, CSV ou un copier-coller</span></summary>
    <div class="muted">Une ligne par ${type === "clients" ? "client" : "point d'intervention"}. Avec une ligne d'en-tête, les colonnes sont reconnues dans n'importe quel ordre ; sinon : ${T[1]}. Les doublons sont ignorés, rien n'est écrasé.</div>
    <div class="row3"><label>Fichier Excel ou CSV<input type="file" data-impf="${type}" accept=".xlsx,.csv,.txt"></label>${type === "magasins" ? `<label>Zone si absente du fichier<select data-impz><option value="">D'après le code postal</option>${(A.L.zones || []).map(z => `<option>${esc(z)}</option>`).join("")}</select></label>` : "<span></span>"}<span></span></div>
    <label>Ou collez les lignes<textarea data-impt="${type}" rows="4"></textarea></label>
    <div class="btns"><button class="ghost" data-imps="${type}">Aperçu</button><button class="primary" data-impgo="${type}" disabled>Importer</button></div><div data-impr="${type}"></div></details>`;
}
function bImport(type, apres){
  const q = s => document.querySelector(s), envoi = async sim => { const fd = new FormData(); fd.append("type", type); fd.append("texte", q(`[data-impt="${type}"]`).value);
    const f = q(`[data-impf="${type}"]`).files[0]; if (f) fd.append("fichier", f); if (q("[data-impz]") && type === "magasins") fd.append("zone", q("[data-impz]").value); if (sim) fd.append("simuler", "1");
    const r = await fetch("gestion_api.php?action=import", {method:"POST", credentials:"same-origin", headers:{"X-Requested-With":"amaya"}, body:fd}); const d = await r.json().catch(() => ({})); if (!r.ok) throw new Error(d.error || "Erreur " + r.status); return d; };
  const bs = q(`[data-imps="${type}"]`), bg = q(`[data-impgo="${type}"]`), out = q(`[data-impr="${type}"]`); if (!bs) return;
  bs.onclick = async () => { try { const d = await envoi(true); bg.disabled = !d.nb;
    out.innerHTML = `<div class="hint"><b>${d.nb}</b> à ajouter${d.nb_ignores ? ` · ${d.nb_ignores} déjà présent${d.nb_ignores > 1 ? "s" : ""} (ignoré${d.nb_ignores > 1 ? "s" : ""})` : ""}</div>${d.apercu.length ? `<div class="tablewrap"><table>${d.apercu.map(x => `<tr>${Object.values(x).slice(0, 5).map(v => `<td>${esc(String(v || ""))}</td>`).join("")}</tr>`).join("")}</table></div>` : ""}`; } catch(e) { toast(e.message); } };
  bg.onclick = async () => { try { const d = await envoi(false); toast(d.nb + (type === "clients" ? " client" : " point") + (d.nb > 1 ? "s" : "") + (type === "clients" ? "" : " de vente") + " importé" + (d.nb > 1 ? "s" : "")); await apres(); } catch(e) { toast(e.message); } };
}

// Maintenance (direction) : version, mises à jour sans FTP, sauvegardes de la base, sécurité des comptes.
const MT = { D:null, paquet:null, busy:false, J:null, jq:{du:"", au:"", q:""} };
async function loadMaint(){ MT.D = await gapi("maint"); }
function vMaint(){
  const D = MT.D; if (!D) return `<div class="card"><div class="muted">Chargement…</div></div>`;
  const ko = (b, l) => b ? `<span class="pill p-ok">${l}</span>` : `<span class="pill p-bad">${l} absent</span>`;
  const mo = n => n > 1048576 ? (n / 1048576).toFixed(1).replace(".", ",") + " Mo" : Math.max(1, Math.round(n / 1024)) + " Ko";
  const M = D.derniere_maj, P = MT.paquet;
  const BZ = D.base || {};
  return `<div class="card accent"><h2>Amaya ${esc(D.version)}</h2>${BZ.attendu ? `<div class="hint">Base de données : évolution n° ${BZ.niveau} sur ${BZ.attendu} ${BZ.a_jour ? `<span class="pill p-ok">à jour</span>` : `<span class="pill p-bad">en retard : rechargez la page</span>`}</div>` : ""}
      <div class="muted">PHP ${esc(D.php)} · ${ko(D.zip, "zip")} ${ko(D.sodium, "sodium")}${M ? ` · dernière mise à jour le ${esc(M.le)} par ${esc(M.par)} (${esc(M.de)} → ${esc(M.vers)})` : ""}</div></div>
    <div class="card"><h2>Mises à jour</h2>
      ${D.distante ? `<div class="mt-new"><b>Version ${esc(D.distante.version)} disponible</b>${D.distante.notes ? `<div class="muted">${esc(D.distante.notes)}</div>` : ""}<div class="btns"><button class="primary" id="mt-dist">Télécharger et vérifier</button></div></div>` : ""}
      <div class="muted">Déposez ici le paquet de mise à jour fourni par l'éditeur (fichier .zip). Sa signature et chaque fichier sont vérifiés avant toute modification ; la base et les fichiers remplacés sont sauvegardés avant l'installation. config.php, les photos et les documents ne sont jamais touchés.</div>
      ${P ? `<div class="mt-new"><b>Paquet vérifié : version ${esc(P.version)}</b> <span class="hint">(installée : ${esc(P.actuelle)})</span>
          <div class="muted">${P.changes} fichier${P.changes > 1 ? "s" : ""} modifié${P.changes > 1 ? "s" : ""} sur ${P.fichiers}${P.date ? " · publié le " + esc(P.date) : ""}</div>${P.notes ? `<div class="mt-notes">${esc(P.notes)}</div>` : ""}
          <div class="btns"><button class="primary" id="mt-go" ${MT.busy ? "disabled" : ""}>${MT.busy ? "Installation…" : "Installer la version " + esc(P.version)}</button><button class="ghost" id="mt-x">Annuler</button></div></div>`
        : `<label class="btn" style="text-align:center">${MT.busy ? "Vérification…" : "Choisir le paquet (.zip)"}<input type="file" id="mt-f" accept=".zip,application/zip" hidden></label>`}</div>
    <div class="card"><h2>Sauvegardes de la base</h2>
      <div class="muted">Une sauvegarde chiffrée par jour, faite automatiquement${D.cron ? " par la tâche planifiée" : " à la première ouverture du tableau de bord de la journée"}. On garde les 14 dernières et la première de chaque mois pendant un an. Téléchargez-en une de temps en temps et rangez-la hors du serveur.</div>
      ${D.sauvegardes.length ? `<div class="tablewrap"><table><tr><th>Sauvegarde</th><th class="num">Taille</th><th></th></tr>${D.sauvegardes.slice(0, 20).map(s => `<tr><td>${esc(s.date)}</td><td class="num">${mo(s.taille)}</td><td class="num"><button class="ghost sm" data-svdl="${esc(s.nom)}">Télécharger</button></td></tr>`).join("")}</table></div>`
        : `<div class="empty">Aucune sauvegarde pour l'instant.</div>`}
      <div class="btns"><button class="ghost" id="mt-sv">Faire une sauvegarde maintenant</button></div>
      <details><summary>Restaurer une sauvegarde</summary><div class="hint">Téléchargez la sauvegarde (fichier .sql.gz), puis chez OVH : Hébergement › Bases de données › votre base › « Importer un fichier ». La base est remplacée par l'état du jour de la sauvegarde.</div></details></div>
    ${(X => `<div class="card"><h2>Copie hors du serveur</h2>
      ${X.actif ? `<div class="muted">Chaque jour, la base, les photos, les documents et config.php sont envoyés chiffrés (clé distincte de celle de l'agence) vers <b>${esc(X.fournisseur)}</b>, dossier <b>${esc(X.prefixe)}</b>. Une vérification de restauration est faite automatiquement chaque semaine.</div>
        <div class="row3"><div><b>Dernier envoi</b><div class="hint">${X.dernier ? esc(new Date(X.dernier * 1000).toLocaleString("fr-FR")) : "jamais"}</div></div>
          <div><b>Fichiers copiés</b><div class="hint">${X.copies} sur ${X.locaux}${X.copies < X.locaux ? " (la suite au prochain passage)" : ""} · ${X.bases} sauvegarde${X.bases > 1 ? "s" : ""} de la base</div></div>
          <div><b>Dernière vérification</b><div class="hint">${X.verif ? `${X.verif.ok ? `<span class="pill p-ok">restauration possible</span>` : `<span class="pill p-bad">à revoir</span>`} ${esc(X.verif.le)}` : "jamais"}</div></div></div>
        ${X.verif ? `<ul class="hint" style="margin:6px 0 0 18px;padding:0">${X.verif.details.map(d => `<li>${esc(d)}</li>`).join("")}</ul>` : ""}
        ${X.erreur ? `<div class="hint" style="color:var(--bad)">Dernière erreur : ${esc(X.erreur)}</div>` : ""}
        <div class="btns"><button class="ghost" id="mt-xs">Envoyer maintenant</button><button class="ghost" id="mt-xv">Vérifier la restauration</button></div>`
      : `<div class="muted"><span class="pill p-bad">Non réglée</span> Les sauvegardes ne sont aujourd'hui que sur ce serveur : une panne ou une intrusion emporterait les données et leurs copies. Créez un conteneur de stockage objet (OVHcloud Object Storage, région différente de l'hébergement) et renseignez SV_S3_ENDPOINT, SV_S3_REGION, SV_S3_BUCKET, SV_S3_CLE_ACCES, SV_S3_SECRET et SV_CLE_EXTERNE dans config.php (voir LISEZMOI).</div>`}</div>`)(D.externe || {})}
    ${D.cle ? vCle(D.cle) : ""}
    ${D.principal ? `<div class="card"><h2>Export complet de l'agence</h2><div class="muted">Base de données, photos, documents déchiffrés et fiches en clair, dans un fichier ZIP chiffré (AES-256). Le mot de passe est affiché une seule fois. Utile pour changer de logiciel ou en fin de contrat. Le fichier est effacé du serveur dès qu'il est téléchargé, ou au bout de 24 h.</div>
      <div class="btns"><button class="ghost" id="mt-exp">Préparer l'export</button></div></div>` : ""}
    ${D.principal ? vMailMaint() : ""}
    ${(G => `<div class="card"><h2>Cartographie</h2>
      ${G.cle ? `<div class="muted">Kilomètres domicile ↔ point de vente et recherche des magasins par leur nom : <b>Geoapify</b>. ${G.etat ? `Dernier échange le ${esc(G.etat.le)} : ${+G.etat.code === 200 ? `<span class="pill p-ok">connecté</span>` : `<span class="pill p-bad">${esc(G.etat.texte)}</span>`}` : "Pas encore utilisé."}</div>
        ${D.principal ? `<div class="btns"><button class="ghost" id="mt-geo">Tester la cartographie</button></div>` : ""}`
      : `<div class="muted"><span class="pill p-warn">Serveurs publics</span> Les kilomètres et la recherche de magasins passent par les serveurs publics d'OpenStreetMap, sans garantie de service. Pour un fournisseur sous contrat : compte Geoapify, puis <code>define('GEO_CLE', '…');</code> dans config.php.</div>`}</div>`)(D.geo || {})}
    <div class="card"><h2>Surveillance</h2><div class="muted">Pour être prévenu si l'appli ne répond plus : créez une sonde gratuite (UptimeRobot, Better Stack…) sur l'adresse ci-dessous, vérifiée toutes les 5 minutes. Elle répond « ok » quand tout fonctionne et n'expose aucune donnée.</div>
      <input readonly value="${esc(D.sante)}" onclick="this.select()"></div>
    <div class="card"><h2>Sécurité des comptes</h2>
      <div class="muted">Double authentification activée pour <b>${D.tf}</b> dirigeant${D.tf > 1 ? "s" : ""} sur ${D.dirigeants}. Chacun l'active dans « Mon compte » (bouton à son nom, en haut à droite).</div>
      ${D.tf < D.dirigeants ? `<div class="hint" style="color:var(--bad)">Recommandé : tous les dirigeants, car ils voient la paye, les IBAN et les n° de sécurité sociale.</div>` : ""}
      ${typeof myAccount === "function" ? `<div class="btns"><button class="ghost" id="mt-acc">Ouvrir mon compte</button></div>` : ""}</div>
    ${D.principal ? vJournal() : ""}`;
}
/** Clé des documents (dirigeants) : où elle est rangée, changement de clé avec rechiffrement, ancienne clé retirée à la fin. */
function vCle(K){
  const R = K.rechiffrement, enCours = K.anciennes > 0;
  return `<div class="card"><h2>Clé des documents</h2>
    <div class="muted">Elle chiffre les pièces d'identité, IBAN, n° de sécurité sociale, CV, contrats signés, secrets de double authentification et sauvegardes.</div>
    <div style="margin:8px 0">${K.source === "fichier" && K.expose ? `<span class="pill p-bad">fichier dans un dossier web</span>` : K.source === "fichier" ? `<span class="pill p-ok">hors du dossier web</span>` : `<span class="pill p-warn">dans config.php</span> <span class="hint">protégée par le serveur ; mieux : la placer hors du dossier web.</span>`}
      ${K.repli ? ` <span class="pill p-bad">ancien fichier de clé dans uploads</span>` : ""}</div>
    ${enCours ? `<div class="mt-new"><b>Changement de clé en cours</b><div class="muted">Commencé le ${esc((R && R.debut) || "")} · ${R ? (R.faits || 0) + " élément(s) rechiffré(s)" + (R.restants ? ", " + R.restants + " restant(s)" : "") + (R.illisibles ? ", " + R.illisibles + " illisible(s)" : "") : ""}${R && R.fin ? " · terminé le " + esc(R.fin) : ""}. La tâche planifiée poursuit d'elle-même.</div>
        <div class="btns"><button class="ghost" id="mt-kr">Poursuivre maintenant</button>${R && R.fin ? `<button class="primary" id="mt-kx">Retirer l'ancienne clé</button>` : ""}</div></div>`
      : `${K.expose && K.hors_web ? `<div class="hint" style="margin-top:6px;color:var(--bad)">Le fichier de la clé est rangé dans un dossier accessible par un site de l'hébergement. Déplacez-le dans <b>${esc(K.hors_web)}</b> : personne ne sera déconnecté.</div><div class="btns"><button class="primary" id="mt-kd">Déplacer le fichier de la clé</button></div>` : ""}<div class="btns"><button class="ghost" id="mt-kopen">${MT.cleOuvert ? "Masquer" : "Changer ou déplacer la clé"}</button></div>${!MT.cleOuvert ? "" : `<div>
        ${K.source !== "fichier" && K.hors_web ? `<div class="hint" style="margin-top:6px">Placer la clé dans <b>${esc(K.hors_web)}</b> (hors du dossier web) : config.php ne garde que le chemin. Une seule fois, chacun devra se reconnecter et l'adresse de surveillance changera.</div><div class="btns"><button class="ghost" id="mt-kd">Placer la clé hors du dossier web</button></div>` : ""}
        ${K.source !== "fichier" ? `<div class="hint" style="margin-top:6px">Changer la clé (fuite possible, départ d'une personne qui y avait accès) : une nouvelle clé est créée, tout est rechiffré par lots, l'ancienne reste lue jusqu'à la fin. Tapez CHANGER pour confirmer.</div>
        <div class="row"><input id="mt-kc" placeholder="CHANGER"><button class="ghost" id="mt-kgo">Changer la clé</button></div>` : `<div class="hint">Clé dans un fichier : pour la changer, suivez la procédure du LISEZMOI.</div>`}</div>`}`}</div>`;
}
/** E-mails : authentification du domaine d'envoi (SPF, DMARC), mode d'envoi, test. Chargé à la demande (requêtes DNS). */
function vMailMaint(){
  const M = MT.M;
  return `<div class="card"><h2>E-mails</h2>${!M ? `<div class="muted">Vérification du domaine d'envoi…</div>` : `
    <div class="muted">Expéditeur <b>${esc(M.expediteur)}</b> · envoi par ${M.mode === "smtp" ? `le serveur SMTP <b>${esc(M.smtp)}</b>` : "l'hébergement"}${M.dernier && M.dernier.erreur ? ` · <span style="color:var(--bad)">dernier envoi refusé : ${esc(M.dernier.erreur)}</span>` : ""}</div>
    <ul class="hint" style="margin:8px 0 0 18px;padding:0">
      <li>SPF (serveurs autorisés à envoyer pour ${esc(M.domaine)}) : ${M.spf ? `<span class="pill p-ok">présent</span> <code>${esc(M.spf)}</code>` : `<span class="pill p-bad">absent</span> : vos e-mails risquent d'arriver en indésirables. Chez OVH, ajoutez l'enregistrement SPF proposé dans la zone DNS du domaine.`}</li>
      <li>DMARC (consigne aux messageries en cas d'usurpation) : ${M.dmarc ? `<span class="pill p-ok">présent</span> politique « ${esc(M.dmarc_politique || "?")} »` : `<span class="pill p-warn">absent</span> : ajoutez un enregistrement TXT <code>_dmarc</code> avec <code>v=DMARC1; p=none; rua=mailto:${esc(M.expediteur)}</code>, puis passez à <code>p=quarantine</code> après quelques semaines.`}</li>
      <li>DKIM (signature des messages) : à activer pour le domaine dans l'espace OVH (rubrique e-mails du domaine) ; il ne se vérifie pas d'ici.</li></ul>`}
    <div class="btns"><button class="ghost" id="mt-mt">M'envoyer un e-mail de test</button></div></div>`;
}
/** Journal des actions sensibles (dirigeants) : 30 derniers jours par défaut, recherche libre, export CSV. Conservé 12 mois. */
function vJournal(){
  const J = MT.J, f = MT.jq, R = {direction:"Direction", marque:"Marque", intervenant:"Intervenant(e)", anonyme:"Non connecté"};
  return `<div class="card"><h2>Journal des accès</h2>
    <div class="muted">Connexions, téléchargements (contrats, CV, exports, fichier SEPA, sauvegardes, pièces), comptes et effacements. Conservé 12 mois.</div>
    <div class="row3"><label>Du<input type="date" id="jr-du" value="${esc(f.du || (J && J.du) || "")}"></label><label>Au<input type="date" id="jr-au" value="${esc(f.au || (J && J.au) || "")}"></label>
      <label>Rechercher<input id="jr-q" placeholder="nom, e-mail, fichier, adresse…" value="${esc(f.q)}"></label></div>
    <div class="btns"><button class="ghost" id="jr-go">Afficher</button><button class="ghost" id="jr-csv">Exporter (CSV)</button></div>
    ${!J ? `<div class="muted">Chargement…</div>` : J.lignes.length ? `<div class="tablewrap"><table><tr><th>Date</th><th>Qui</th><th>Action</th><th>Détail</th><th>Adresse</th></tr>
      ${J.lignes.map(l => `<tr><td style="white-space:nowrap">${esc(l.le.slice(0, 16).replace(/^(\d+)-(\d+)-(\d+)/, "$3/$2/$1"))}</td><td>${esc(l.qui)}<div class="hint">${esc(R[l.role] || l.role)}</div></td><td>${esc(l.action)}</td><td>${esc(l.objet)}</td><td class="hint">${esc(l.ip)}</td></tr>`).join("")}</table></div>
      ${J.lignes.length >= 500 ? `<div class="hint">500 lignes affichées au plus : précisez la période ou la recherche, ou exportez.</div>` : ""}` : `<div class="empty">Aucune ligne pour cette période.</div>`}</div>`;
}
async function loadJournal(){ const f = MT.jq; try { MT.J = await gapi("journal", null, new URLSearchParams({du:f.du, au:f.au, q:f.q}).toString()); } catch(x) { MT.J = {lignes:[]}; toast(x.message); } redraw(); }
function D_principal(){ return !!(MT.D && MT.D.principal); }
function bMaint(){
  const re = () => redraw();
  if ($("#mt-f")) $("#mt-f").onchange = async e => { const f = e.target.files[0]; if (!f) return; MT.busy = true; re();
    try { const fd = new FormData(); fd.append("paquet", f);
      const r = await fetch("gestion_api.php?action=maj_deposer", {method:"POST", credentials:"same-origin", headers:{"X-Requested-With":"amaya"}, body:fd});
      const d = await r.json().catch(() => ({})); if (!r.ok) throw new Error(d.error || "Erreur " + r.status); MT.paquet = d; } catch(x) { toast(x.message); }
    MT.busy = false; re(); };
  if ($("#mt-dist")) $("#mt-dist").onclick = async () => { MT.busy = true; re(); try { MT.paquet = await gapi("maj_distante", {}); } catch(x) { toast(x.message); } MT.busy = false; re(); };
  if ($("#mt-x")) $("#mt-x").onclick = () => { MT.paquet = null; re(); };
  if ($("#mt-go")) $("#mt-go").onclick = async () => { MT.busy = true; re();
    try { const r = await gapi("maj_appliquer", {}); toast("Amaya " + r.version + " installée (" + r.fichiers + " fichiers)"); setTimeout(() => location.reload(), 900); }
    catch(x) { toast(x.message); MT.busy = false; re(); } };
  if ($("#mt-xs")) $("#mt-xs").onclick = async () => { const b = $("#mt-xs"); b.disabled = true; b.textContent = "Envoi…"; try { const r = await gapi("externe_envoyer", {}); toast(`${r.bilan.fichiers} fichier(s) et ${r.bilan.base} sauvegarde(s) envoyés${r.bilan.reste ? ", " + r.bilan.reste + " restant(s)" : ""}`); MT.D = await gapi("maint"); re(); } catch(x) { toast(x.message); b.disabled = false; b.textContent = "Envoyer maintenant"; } };
  if ($("#mt-xv")) $("#mt-xv").onclick = async () => { const b = $("#mt-xv"); b.disabled = true; b.textContent = "Vérification…"; try { const r = await gapi("externe_verifier", {}); toast(r.verif.ok ? "Restauration vérifiée" : "Vérification : points à revoir"); MT.D = await gapi("maint"); re(); } catch(x) { toast(x.message); b.disabled = false; b.textContent = "Vérifier la restauration"; } };
  $("#mt-sv").onclick = async () => { try { const r = await gapi("sauvegarde_faire", {}); MT.D.sauvegardes = r.sauvegardes; toast("Sauvegarde faite"); re(); } catch(x) { toast(x.message); } };
  document.querySelectorAll("[data-svdl]").forEach(b => b.onclick = () => { window.location.href = "gestion_api.php?action=sauvegarde_dl&nom=" + encodeURIComponent(b.dataset.svdl); });
  if ($("#mt-acc")) $("#mt-acc").onclick = () => myAccount();
  if ($("#mt-kopen")) $("#mt-kopen").onclick = () => { MT.cleOuvert = !MT.cleOuvert; re(); };
  const kact = async (id, action, body, msg) => { const b = $(id); b.disabled = true; try { const r = await gapi(action, body); msg(r); MT.D = await gapi("maint"); re(); } catch(x) { toast(x.message); b.disabled = false; } };
  if ($("#mt-kr")) $("#mt-kr").onclick = () => kact("#mt-kr", "cle_rechiffrer", {}, r => toast(r.bilan.faits + " élément(s) rechiffré(s)" + (r.bilan.restants ? ", " + r.bilan.restants + " restant(s)" : ", terminé")));
  if ($("#mt-kx")) $("#mt-kx").onclick = () => kact("#mt-kx", "cle_retirer", {}, () => toast("Ancienne clé retirée de config.php"));
  if ($("#mt-kd")) $("#mt-kd").onclick = () => kact("#mt-kd", "cle_deplacer", {}, r => { toast("Clé placée dans " + r.fichier); setTimeout(() => location.reload(), 2500); });
  if ($("#mt-kgo")) $("#mt-kgo").onclick = () => kact("#mt-kgo", "cle_changer", {confirmation:$("#mt-kc").value.trim()}, r => {
    const m = document.createElement("div"); m.className = "modal"; m.innerHTML = `<div class="modal-box"><h2>Nouvelle clé des documents</h2><div class="muted">Notez-la tout de suite dans votre coffre de mots de passe, hors du serveur. Elle ne sera plus jamais affichée. Le rechiffrement a commencé.</div><pre style="font-size:15px;user-select:all;white-space:pre-wrap;word-break:break-all">${esc(r.cle)}</pre><div class="btns"><button class="primary" data-x>C'est noté</button></div></div>`;
    document.body.appendChild(m); m.onclick = e => { if (e.target.closest("[data-x]")) m.remove(); }; });
  if ($("#mt-exp")) $("#mt-exp").onclick = async () => { const b = $("#mt-exp"); b.disabled = true; b.textContent = "Préparation… (quelques minutes)";
    try { const r = await gapi("exp_agence", {}); const m = document.createElement("div"); m.className = "modal";
      m.innerHTML = `<div class="modal-box"><h2>Export prêt</h2><div class="muted">${(r.taille / 1048576).toFixed(1).replace(".", ",")} Mo. Notez le mot de passe du fichier ZIP, il ne sera plus affiché (7-Zip ou WinRAR pour l'ouvrir) :</div><pre style="font-size:16px;user-select:all">${esc(r.mdp)}</pre>
        <div class="btns"><a class="btn primary" href="gestion_api.php?action=exp_telecharger" data-x>Télécharger</a><button class="ghost" data-x>Fermer</button></div></div>`;
      document.body.appendChild(m); m.onclick = e => { if (e.target.closest("[data-x]")) setTimeout(() => m.remove(), 300); }; } catch(x) { toast(x.message); }
    b.disabled = false; b.textContent = "Préparer l'export"; };
  if (D_principal() && !MT.M && !MT.mload) { MT.mload = true; gapi("mail_etat").then(r => { MT.M = r; re(); }).catch(() => {}).finally(() => MT.mload = false); }
  if ($("#mt-mt")) $("#mt-mt").onclick = async () => { const b = $("#mt-mt"); b.disabled = true; try { const r = await gapi("mail_test", {}); toast(r.ok ? "E-mail de test envoyé à " + r.a + " : vérifiez qu'il n'est pas dans les indésirables." : "Envoi impossible : " + r.erreur); } catch(x) { toast(x.message); } b.disabled = false; };
  if ($("#mt-geo")) $("#mt-geo").onclick = async () => { const b = $("#mt-geo"); b.disabled = true; b.textContent = "Test…";
    try { const r = await gapi("geo_tester", {}); toast((r.ok ? "Cartographie : " : "Problème : ") + r.texte); MT.D = await gapi("maint"); re(); } catch(x) { toast(x.message); b.disabled = false; b.textContent = "Tester la cartographie"; } };
  const jf = () => { MT.jq = {du:$("#jr-du").value, au:$("#jr-au").value, q:$("#jr-q").value.trim()}; };
  if ($("#jr-go")) { $("#jr-go").onclick = () => { jf(); MT.J = null; redraw(); loadJournal(); }; $("#jr-q").onkeydown = e => { if (e.key === "Enter") $("#jr-go").click(); };
    $("#jr-csv").onclick = () => { jf(); window.location.href = "gestion_api.php?action=journal&csv=1&" + new URLSearchParams(MT.jq).toString(); };
    if (!MT.J && !MT.jload) { MT.jload = true; loadJournal().finally(() => MT.jload = false); } }
}
