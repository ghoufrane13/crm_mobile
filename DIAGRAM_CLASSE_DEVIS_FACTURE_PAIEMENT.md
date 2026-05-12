# Diagramme de Classes - Devis, Facture et Paiement

## 📋 Vue d'ensemble

Ce diagramme de classes montre l'architecture de la partie **Devis (Estimate) → Facture (Invoice) → Paiement (Payment)** avec les relations au **Personnel (Staff)** de l'application CodeIgniter 4.

---

## 🗂️ Structure des Classes

### 1. **Estimate (Devis)** - Table: `tblestimates`

Représente un devis envoyé à un client.

#### Colonnes principales:
| Colonne | Type | Description |
|---------|------|-------------|
| `id` | int | Identifiant unique (PK) |
| `clientid` | int | Référence au client (FK tblclients) |
| `number` | string | Numéro du devis |
| `prefix` | string | Préfixe du numéro |
| `formatted_number` | string | Numéro formaté affiché |
| `date` | date | Date de création |
| `expirydate` | date | Date d'expiration |
| `currency` | int | Devise utilisée (FK tblcurrencies) |
| `subtotal` | decimal | Sous-total HT |
| `total_tax` | decimal | Total taxes |
| `total` | decimal | Total TTC |
| `discount_percent` | decimal | % de remise |
| `discount_total` | decimal | Montant remise |
| `discount_type` | string | Type remise (fixed/percent) |
| `adjustment` | decimal | Ajustement |
| `status` | int | État (1=Brouillon, 2=Envoyé, 3=Décliné, 4=Accepté, 5=Expiré) |
| `sale_agent` | int | Agent commercial (FK tblstaff) |
| `addedfrom` | int | Créé par (FK tblstaff) |
| `terms` | text | Conditions générales |
| `sent` | date | Date d'envoi |
| `reference_no` | string | Numéro de référence |
| `billing_*` | string | Adresse de facturation |
| `shipping_*` | string | Adresse de livraison |

#### Méthodes principales:
- `getList()` - Liste paginée avec filtres
- `getDetail()` - Détail complet avec articles
- `getItems()` - Articles du devis

---

### 2. **Invoice (Facture)** - Table: `tblinvoices`

Représente une facture créée généralement à partir d'un devis.

#### Colonnes principales:
| Colonne | Type | Description |
|---------|------|-------------|
| `id` | int | Identifiant unique (PK) |
| `clientid` | int | Référence au client (FK tblclients) |
| `number` | string | Numéro de facture |
| `prefix` | string | Préfixe du numéro |
| `formatted_number` | string | Numéro formaté affiché |
| `date` | date | Date d'émission |
| `duedate` | date | Date d'échéance |
| `currency` | int | Devise utilisée (FK tblcurrencies) |
| `subtotal` | decimal | Sous-total HT |
| `total_tax` | decimal | Total taxes |
| `total` | decimal | Total TTC |
| `discount_percent` | decimal | % de remise |
| `discount_total` | decimal | Montant remise |
| `discount_type` | string | Type remise |
| `adjustment` | decimal | Ajustement |
| `status` | int | État |
| `sale_agent` | int | Agent commercial (FK tblstaff) |
| `addedfrom` | int | Créé par (FK tblstaff) |
| `terms` | text | Conditions générales |
| `sent` | date | Date d'envoi |
| `adminnote` | text | Note admin |
| `clientnote` | text | Note pour le client |
| `hash` | string | Hash unique |
| `recurring` | int | Si récurrente |
| `cancel_overdue_reminders` | int | Annuler les rappels en retard |

#### Méthodes principales:
- `getWithRelations()` - Invoice avec client et devise
- `getItems()` - Articles liés
- `getTotalPaid()` - Total payé

---

### 3. **Payment (Paiement)** - Table: `tblinvoicepaymentrecords`

Enregistre chaque paiement reçu pour une facture.

#### Colonnes principales:
| Colonne | Type | Description |
|---------|------|-------------|
| `id` | int | Identifiant unique (PK) |
| `invoiceid` | int | Facture payée (FK tblinvoices) |
| `amount` | decimal | Montant payé |
| `paymentmode` | string | Mode de paiement (Virement, Chèque, Carte, etc.) |
| `paymentmethod` | string | Méthode technique (stripe, paypal, etc.) |
| `transactionid` | string | Numéro de transaction |
| `note` | text | Notes du paiement |
| `date` | date | Date du paiement |
| `daterecorded` | datetime | Date/heure d'enregistrement |

#### Méthodes principales:
- `getList()` - Tous les paiements, optionnellement filtrés par facture
- `getDetail()` - Détail d'un paiement
- `getTotalPaid()` - Total payé pour une facture

---

### 4. **ItemEstimate (Article)** - Table: `tblitemable`

Articles/lignes ajoutées dans un devis ou une facture.

#### Colonnes principales:
| Colonne | Type | Description |
|---------|------|-------------|
| `rel_id` | int | ID du document parent (estimate/invoice) |
| `rel_type` | string | Type: 'estimate', 'invoice', ou 'proposal' |
| `qty` | decimal | Quantité |
| `rate` | decimal | Tarif unitaire |
| `taxname` | string | Nom de la taxe |
| `taxrate` | decimal | Taux de la taxe |
| `item_order` | int | Ordre d'affichage |

---

### 5. **Client** - Table: `tblclients`

Informations client.

#### Colonnes principales:
| Colonne | Type | Description |
|---------|------|-------------|
| `userid` | int | Identifiant unique (PK) |
| `company` | string | Nom de l'entreprise |
| `vat` | string | Numéro TVA |
| `email` | string | Email |
| `phonenumber` | string | Téléphone |
| `address` | string | Adresse |
| `city` | string | Ville |
| `state` | string | État/Province |
| `zip` | string | Code postal |
| `country` | string | Pays |
| `website` | string | Site web |
| `default_currency` | int | Devise par défaut (FK tblcurrencies) |
| `default_language` | string | Langue par défaut |
| `active` | boolean | Client actif |
| `stripe_id` | string | ID Stripe |
| `datecreated` | datetime | Date création |

---

### 6. **Currency (Devise)** - Table: `tblcurrencies`

Devises supportées.

#### Colonnes principales:
| Colonne | Type | Description |
|---------|------|-------------|
| `id` | int | Identifiant (PK) |
| `symbol` | string | Symbole (€, $, £, etc.) |
| `name` | string | Nom (Euro, Dollar, etc.) |

---

### 7. **Tax (Taxe)** - Table: `tbltaxes`

Types de taxes.

#### Colonnes principales:
| Colonne | Type | Description |
|---------|------|-------------|
| `id` | int | Identifiant (PK) |
| `name` | string | Nom de la taxe (TVA, TST, etc.) |
| `taxrate` | decimal | Taux (ex: 20.00 pour 20%) |

---

### 8. **Staff (Personnel)** - Table: `tblstaff`

Représente un membre du personnel (agent commercial, administrateur, etc.).

#### Colonnes principales:
| Colonne | Type | Description |
|---------|------|-------------|
| `staffid` | int | Identifiant unique (PK) |
| `email` | string | Email (unique) |
| `firstname` | string | Prénom |
| `lastname` | string | Nom |
| `phonenumber` | string | Numéro de téléphone |
| `role` | string | Rôle (Commercial, Admin, Support, etc.) |
| `admin` | boolean | Est administrateur |
| `active` | boolean | Staff actif |
| `hourly_rate` | decimal | Tarif horaire |
| `profile_image` | string | Image de profil |
| `datecreated` | datetime | Date création |
| `last_login` | datetime | Dernière connexion |

#### Relations avec Devis/Facture:
- **`sale_agent`** : L'agent commercial responsable du devis/facture
- **`addedfrom`** : Le staff qui a créé le document

---

## 📊 Relations

### Relations Principales:

1. **Estimate → Client** (Many-to-One)
   - Plusieurs devis appartiennent à 1 client
   - Clé: `Estimate.clientid = Client.userid`

2. **Invoice → Client** (Many-to-One)
   - Plusieurs factures appartiennent à 1 client
   - Clé: `Invoice.clientid = Client.userid`

3. **Estimate → Staff (sale_agent)** (Many-to-One)
   - Plusieurs devis peuvent être assignés à 1 agent commercial
   - Clé: `Estimate.sale_agent = Staff.staffid`
   - **Description** : L'agent commercial responsable de la vente

4. **Estimate → Staff (addedfrom)** (Many-to-One)
   - Plusieurs devis créés par 1 staff
   - Clé: `Estimate.addedfrom = Staff.staffid`
   - **Description** : Le staff qui a créé le devis

5. **Invoice → Staff (sale_agent)** (Many-to-One)
   - Plusieurs factures peuvent être assignées à 1 agent commercial
   - Clé: `Invoice.sale_agent = Staff.staffid`

6. **Invoice → Staff (addedfrom)** (Many-to-One)
   - Plusieurs factures créées par 1 staff
   - Clé: `Invoice.addedfrom = Staff.staffid`

7. **Payment → Invoice** (Many-to-One)
   - Plusieurs paiements pour 1 facture
   - Clé: `Payment.invoiceid = Invoice.id`

8. **Estimate → ItemEstimate** (One-to-Many)
   - 1 devis contient plusieurs articles
   - Clé: `ItemEstimate.rel_id = Estimate.id` ET `ItemEstimate.rel_type = 'estimate'`

9. **Invoice → ItemEstimate** (One-to-Many)
   - 1 facture contient plusieurs articles
   - Clé: `ItemEstimate.rel_id = Invoice.id` ET `ItemEstimate.rel_type = 'invoice'`

10. **Estimate → Currency** (Many-to-One)
    - Clé: `Estimate.currency = Currency.id`

11. **Invoice → Currency** (Many-to-One)
    - Clé: `Invoice.currency = Currency.id`

12. **ItemEstimate → Tax** (Many-to-One)
    - Clé: `ItemEstimate.taxname = Tax.name` ET `ItemEstimate.taxrate = Tax.taxrate`

---

## 🔄 Workflow Devis → Facture → Paiement

```
1. STAFF crée et gère un DEVIS (Estimate)
   ├─ Créé par : addedfrom (Staff)
   ├─ Responsable commercial : sale_agent (Staff)
   ├─ État: Brouillon → Envoyé → Accepté
   └─ Envoyé au CLIENT

2. Le devis accepté peut être converti en FACTURE (Invoice)
   ├─ Créé par : addedfrom (Staff)
   ├─ Responsable commercial : sale_agent (Staff)
   ├─ Articles copiés du devis
   └─ État: Brouillon → Envoyé

3. CLIENT effectue un PAIEMENT (Payment)
   ├─ Enregistrement dans Payment liée à Invoice
   └─ Suivi du montant total payé vs total facture

4. RAPPORTS & SUIVI (accessible via Staff):
   ├─ Devis par agent commercial (sale_agent)
   ├─ Factures créées par staff (addedfrom)
   ├─ Montant payé = SUM(Payment.amount) WHERE Payment.invoiceid = Invoice.id
   └─ Montant restant = Invoice.total - Montant payé
```

---

## 🛠️ Contrôleurs

### EstimateController
- `list()` - Liste les devis
- `detail()` - Détail d'un devis
- `create()` - Création
- `update()` - Modification
- `pdf()` - Génération PDF
- `convert()` - Conversion en facture

### InvoiceController
- `list()` - Liste les factures
- `detail()` - Détail
- `create()` - Création
- `update()` - Modification
- `pdf()` - Génération PDF
- `getItems()` - Articles

### PaymentController
- `list()` - Liste des paiements
- `detail()` - Détail d'un paiement
- `create()` - Enregistrement d'un paiement
- `getTotalPaid()` - Total payé pour une facture

---

## 👤 Rôle du Staff

Le Staff intervient à plusieurs niveaux :

| Rôle | Fonction | Colonnes |
|------|----------|----------|
| **Agent Commercial** | Responsable de la relation client | `Estimate.sale_agent`, `Invoice.sale_agent` |
| **Créateur** | Personne qui a créé le document | `Estimate.addedfrom`, `Invoice.addedfrom` |
| **Admin** | Peut voir tous les devis/factures | `Staff.admin` = 1 |
| **Commercial** | Voir ses propres devis/factures | `Staff.role` = 'commercial' |

### Requêtes SQL typiques avec Staff:

```sql
-- Tous les devis d'un agent commercial
SELECT * FROM tblestimates 
WHERE sale_agent = :staffid OR addedfrom = :staffid;

-- Devis créés par un staff
SELECT * FROM tblestimates 
WHERE addedfrom = :staffid;

-- Factures avec info agent commercial
SELECT i.*, s.firstname, s.lastname 
FROM tblinvoices i
LEFT JOIN tblstaff s ON s.staffid = i.sale_agent;

-- Revenue par agent commercial
SELECT s.firstname, s.lastname, SUM(i.total) as revenue
FROM tblinvoices i
LEFT JOIN tblstaff s ON s.staffid = i.sale_agent
GROUP BY i.sale_agent;
```

---

## 🛠️ Contrôleurs

## 📝 Notes Importantes

1. **Statuts Devis**: 1=Brouillon, 2=Envoyé, 3=Décliné, 4=Accepté, 5=Expiré
2. **Articles**: Utilisent la table `tblitemable` avec `rel_type` pour identifier le document parent
3. **Devise**: Chaque devis/facture/paiement utilise une devise spécifique
4. **Taxes**: Stockées dans les articles avec `taxname` et `taxrate`
5. **Client**: Clé primaire = `userid` (pas `id`)
6. **Staff**: Chaque devis/facture a 2 relations Staff:
   - **`sale_agent`** = Agent commercial responsable de la vente
   - **`addedfrom`** = Staff qui a créé le document
7. **Adresses**: Devis et Client supportent les adresses de facturation ET livraison

---

*Diagramme généré le: 2026-04-26*
