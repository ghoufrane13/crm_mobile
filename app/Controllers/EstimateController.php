<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\EstimateModel;
use App\Models\SignatureModel;
use TCPDF;

class EstimateController extends ResourceController
{
    protected $db;
    protected $estimateModel;
    protected $signatureModel;

    private array $statuses = [
        1 => 'Brouillon', 2 => 'Envoyé', 3 => 'Décliné', 4 => 'Accepté', 5 => 'Expiré',
    ];

    public function __construct()
    {
        $this->db            = \Config\Database::connect();
        $this->estimateModel = new EstimateModel();
        $this->signatureModel= new SignatureModel();
    }

    private function _generateEstimateRef(): string
    {
        return 'EST-REF-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    }

    public function list()
    {
        $estimates = $this->estimateModel->getList(
            ($s = $this->request->getGet('staff_id')) ? (int)$s : null,
            ($t = $this->request->getGet('status'))   ? (int)$t : null
        );
        foreach ($estimates as &$e) $e['status_label'] = $this->statuses[(int)$e['status']] ?? 'Inconnu';
        return $this->respond(['status' => true, 'estimates' => $estimates]);
    }

    public function detail($id = null)
    {
        if (!$id) return $this->respond(['status'=>false,'message'=>'ID manquant'],400);
        $estimate = $this->estimateModel->getDetail((int)$id);
        if (!$estimate) return $this->respond(['status'=>false,'message'=>'Devis introuvable'],404);
        $estimate['status_label'] = $this->statuses[(int)$estimate['status']] ?? 'Inconnu';
        $sig = $this->signatureModel->getSignature('quote',(int)$id);
        $estimate['signature'] = $sig
            ? ['signed'=>true,'signed_at'=>$sig['signed_at'],'signature_url'=>$sig['signature_url']]
            : ['signed'=>false];
        return $this->respond(['status'=>true,'estimate'=>$estimate]);
    }

    public function nextNumber()
    {
        $next = $this->estimateModel->getNextNumber();
        return $this->respond(['status'=>true,'prefix'=>$next['prefix'],'number'=>$next['number']]);
    }

    public function generateRef()
    {
        return $this->respond(['status'=>true,'reference'=>$this->_generateEstimateRef()]);
    }

    public function create()
    {
        $data = $this->request->getJSON(true);
        if (empty($data))                                        return $this->respond(['status'=>false,'message'=>'Données manquantes'],400);
        if (empty($data['clientid']))                            return $this->respond(['status'=>false,'message'=>'Client requis'],400);
        if (empty($data['items'])||!is_array($data['items']))   return $this->respond(['status'=>false,'message'=>'Au moins un article est requis'],400);

        $prefix=$data['prefix']??'EST-'; $number=(int)($data['number']??1);
        $formattedNumber=$prefix.str_pad($number,6,'0',STR_PAD_LEFT);
        $referenceNo=trim($data['reference_no']??'');
        if(empty($referenceNo)) $referenceNo=$this->_generateEstimateRef();

        $this->estimateModel->insert([
            'clientid'=>(int)$data['clientid'],'project_id'=>0,'number'=>$number,'prefix'=>$prefix,
            'number_format'=>1,'formatted_number'=>$formattedNumber,'reference_no'=>$referenceNo,
            'date'=>$data['date']??date('Y-m-d'),'expirydate'=>$data['expirydate']??date('Y-m-d',strtotime('+30 days')),
            'currency'=>(int)($data['currency']??1),'status'=>(int)($data['status']??1),
            'sale_agent'=>(int)($data['sale_agent']??$data['staff_id']??0),
            'addedfrom'=>(int)($data['staff_id']??1),
            'subtotal'=>(float)($data['subtotal']??0),'total_tax'=>(float)($data['total_tax']??0),
            'total'=>(float)($data['total']??0),'adjustment'=>(float)($data['adjustment']??0),
            'discount_type'=>$data['discount_type']??'','discount_percent'=>(float)($data['discount_percent']??0),
            'discount_total'=>(float)($data['discount_total']??0),
            'billing_street'=>$data['billing_street']??'','billing_city'=>$data['billing_city']??'',
            'billing_state'=>$data['billing_state']??'','billing_zip'=>$data['billing_zip']??'',
            'billing_country'=>(int)($data['billing_country']??0),'include_shipping'=>(int)($data['include_shipping']??0),
            'shipping_street'=>$data['shipping_street']??'','shipping_city'=>$data['shipping_city']??'',
            'shipping_state'=>$data['shipping_state']??'','shipping_zip'=>$data['shipping_zip']??'',
            'shipping_country'=>(int)($data['shipping_country']??0),'show_shipping_on_estimate'=>1,
            'sent'=>0,'datecreated'=>date('Y-m-d H:i:s'),
        ]);
        $estimateId=$this->estimateModel->getInsertID();
        if(!$estimateId) return $this->respond(['status'=>false,'message'=>'Erreur création'],500);
        $this->estimateModel->insertItems($estimateId,$data['items']);
        return $this->respond(['status'=>true,'message'=>"Devis $formattedNumber créé",
            'estimate_id'=>$estimateId,'formatted_number'=>$formattedNumber,'reference_no'=>$referenceNo],201);
    }

    public function update($id=null)
    {
        if(!$id) return $this->respond(['status'=>false,'message'=>'ID manquant'],400);
        $data=$this->request->getJSON(true);
        $existing=$this->estimateModel->find((int)$id);
        if(!$existing) return $this->respond(['status'=>false,'message'=>'Devis introuvable'],404);

        $u=[];
        foreach(['date','expirydate','reference_no','discount_type','terms','billing_street','billing_city',
                 'billing_state','billing_zip','shipping_street','shipping_city','shipping_state','shipping_zip',
                 'clientnote','adminnote'] as $f) if(array_key_exists($f,$data)) $u[$f]=$data[$f];
        foreach(['currency','clientid','billing_country','shipping_country','include_shipping','sale_agent','status'] as $f)
            if(array_key_exists($f,$data)) $u[$f]=(int)$data[$f];
        foreach(['subtotal','total_tax','total','adjustment','discount_percent','discount_total'] as $f)
            if(array_key_exists($f,$data)) $u[$f]=(float)$data[$f];
        if(!empty($u)) $this->db->table('tblestimates')->where('id',(int)$id)->update($u);
        if(!empty($data['items'])&&is_array($data['items'])) {
            $this->estimateModel->deleteItems((int)$id);
            $this->estimateModel->insertItems((int)$id,$data['items']);
        }
        return $this->respond(['status'=>true,'message'=>'Devis mis à jour avec succès']);
    }

    public function delete($id=null)
    {
        if(!$id) return $this->respond(['status'=>false,'message'=>'ID manquant'],400);
        $estimate=$this->db->table('tblestimates')->select('id,status,invoiceid')->where('id',(int)$id)->get()->getRowArray();
        if(!$estimate) return $this->respond(['status'=>false,'message'=>'Devis introuvable'],404);
        if(!empty($estimate['invoiceid'])) return $this->respond(['status'=>false,'message'=>'Ce devis a été converti en facture.'],400);
        if((int)$estimate['status']===4) return $this->respond(['status'=>false,'message'=>'Un devis accepté ne peut pas être supprimé.'],400);
        try {
            $this->db->table('tblitemable')->where('rel_id',(int)$id)->where('rel_type','estimate')->delete();
            $this->db->table('tblestimates')->where('id',(int)$id)->delete();
            return $this->respond(['status'=>true,'message'=>'Devis supprimé avec succès.']);
        } catch(\Exception $e) {
            return $this->respond(['status'=>false,'message'=>'Erreur suppression : '.$e->getMessage()],500);
        }
    }

    public function changeStatus()
    {
        $data=$this->request->getJSON(true);
        if(empty($data['estimate_id'])||!isset($data['status']))
            return $this->respond(['status'=>false,'message'=>'estimate_id et status requis'],400);
        $status=(int)$data['status'];
        $this->estimateModel->updateStatus((int)$data['estimate_id'],$status);
        return $this->respond(['status'=>true,'message'=>'Statut : '.($this->statuses[$status]??''),'status_label'=>$this->statuses[$status]??'']);
    }

    public function sendEmail($id=null)
    {
        if(!$id) return $this->respond(['status'=>false,'message'=>'ID manquant'],400);
        $data=$this->request->getJSON(true);
        $estimate=$this->estimateModel->getDetail((int)$id);
        if(!$estimate) return $this->respond(['status'=>false,'message'=>'Devis introuvable'],404);
        $toEmail=$estimate['client_email']??''; $clientName=$estimate['client_company']??$estimate['clientname']??'';
        if(empty($toEmail)) return $this->respond(['status'=>false,'message'=>'Aucune adresse email'],400);
        $staffId=(int)($data['staff_id']??0);
        $staff=$staffId?$this->db->table('tblstaff')->where('staffid',$staffId)->get()->getRowArray():null;
        $staffName=$staff?trim(($staff['firstname']??'').' '.($staff['lastname']??'')):'Votre commercial';
        $estimate['status_label']=$this->statuses[(int)$estimate['status']]??'Inconnu';
        if(!$this->_sendEstimateEmail($toEmail,$clientName,$staffName,(int)$id,$estimate))
            return $this->respond(['status'=>false,'message'=>'Erreur envoi email'],500);
        $this->estimateModel->updateStatus((int)$id,2);
        return $this->respond(['status'=>true,'message'=>"Devis envoyé à $toEmail"]);
    }

    public function pdf($id=null)
    {
        if(!$id) return $this->respond(['status'=>false,'message'=>'ID manquant'],400);
        $estimate=$this->estimateModel->getDetail((int)$id);
        if(!$estimate) return $this->respond(['status'=>false,'message'=>'Devis introuvable'],404);
        $estimate['status_label']=$this->statuses[(int)$estimate['status']]??'Inconnu';
        try {
            $bytes=$this->_generatePdfBytes($estimate);
            $filename='devis_'.($estimate['formatted_number']??$id).'.pdf';
            return $this->respond(['status'=>true,'pdf'=>base64_encode($bytes),'filename'=>$filename,'size'=>strlen($bytes)]);
        } catch(\Throwable $e) {
            log_message('error','PDF view error: '.$e->getMessage());
            return $this->respond(['status'=>false,'message'=>'Erreur génération PDF : '.$e->getMessage()],500);
        }
    }

    public function pdfDownload($id=null)
    {
        if(!$id) return $this->respond(['status'=>false,'message'=>'ID manquant'],400);
        $estimate=$this->estimateModel->getDetail((int)$id);
        if(!$estimate) return $this->respond(['status'=>false,'message'=>'Devis introuvable'],404);
        $estimate['status_label']=$this->statuses[(int)$estimate['status']]??'Inconnu';
        $filename='devis_'.($estimate['formatted_number']??$id).'.pdf';
        try {
            $bytes=$this->_generatePdfBytes($estimate);
            return $this->response
                ->setHeader('Content-Type','application/pdf')
                ->setHeader('Content-Disposition','attachment; filename="'.$filename.'"')
                ->setHeader('Content-Length',(string)strlen($bytes))
                ->setHeader('Cache-Control','no-cache, no-store')
                ->setBody($bytes);
        } catch(\Throwable $e) {
            log_message('error','PDF download error: '.$e->getMessage());
            return $this->respond(['status'=>false,'message'=>'Erreur génération PDF'],500);
        }
    }

    public function convert($id=null)
    {
        if(!$id) return $this->respond(['status'=>false,'message'=>'ID manquant'],400);
        $data=$this->request->getJSON(true)??[];
        $estimate=$this->estimateModel->getDetail((int)$id);
        if(!$estimate) return $this->respond(['status'=>false,'message'=>'Devis introuvable'],404);

        if((int)$estimate['status'] !== 4)
            return $this->respond(['status'=>false,'message'=>'Seul un devis Accepté peut être converti en facture.'],400);

        $items=(!empty($data['items'])&&is_array($data['items']))?$data['items']:$estimate['items'];
        $invoiceId=$this->_createInvoice($estimate,$items,$data);
        if(!$invoiceId) return $this->respond(['status'=>false,'message'=>'Erreur création facture'],500);

        $this->db->table('tblestimates')->where('id',(int)$id)->update([
            'invoiceid'    => $invoiceId,
            'invoiced_date'=> date('Y-m-d'),
        ]);
        $this->_copySignatureToInvoice((int)$id,$invoiceId);
        $inv=$this->db->table('tblinvoices')->select('formatted_number')->where('id',$invoiceId)->get()->getRowArray();
        return $this->respond(['status'=>true,'message'=>'Devis converti en facture','invoice_id'=>$invoiceId,
            'formatted_number'=>$inv['formatted_number']??('INV-'.str_pad($invoiceId,6,'0',STR_PAD_LEFT))]);
    }

    public function clientList()
    {
        $contactId=(int)$this->request->getGet('contact_id');
        if(!$contactId) return $this->respond(['status'=>false,'message'=>'contact_id requis'],400);
        $contact=$this->db->table('tblcontacts')->select('id,userid')->where('id',$contactId)->get()->getRowArray();
        if(!$contact) return $this->respond(['status'=>false,'message'=>'Contact introuvable'],404);
        $estimates=$this->db->table('tblestimates e')
            ->select('e.id,e.formatted_number,e.prefix,e.number,e.date,e.expirydate,e.total,e.subtotal,e.status,e.reference_no,cur.symbol AS currency_symbol,c.company AS clientname')
            ->join('tblcurrencies cur','cur.id=e.currency','left')->join('tblclients c','c.userid=e.clientid','left')
            ->where('e.clientid',(int)$contact['userid'])->where('e.status !=',1)->orderBy('e.id','DESC')->get()->getResultArray();
        foreach($estimates as &$e) { $e['status_label']=$this->statuses[(int)$e['status']]??'Inconnu'; $e['is_signed']=$this->signatureModel->isSigned('quote',(int)$e['id']); }
        return $this->respond(['status'=>true,'estimates'=>$estimates]);
    }

    public function clientDetail($id=null)
    {
        if(!$id) return $this->respond(['status'=>false,'message'=>'ID manquant'],400);
        $contactId=(int)$this->request->getGet('contact_id');
        $contact=$this->db->table('tblcontacts')->select('userid')->where('id',$contactId)->get()->getRowArray();
        if(!$contact) return $this->respond(['status'=>false,'message'=>'Contact invalide'],403);
        $estimate=$this->estimateModel->getDetail((int)$id);
        if(!$estimate||(int)$estimate['clientid']!==(int)$contact['userid']) return $this->respond(['status'=>false,'message'=>'Devis introuvable'],404);
        $estimate['status_label']=$this->statuses[(int)$estimate['status']]??'Inconnu';
        $sig=$this->signatureModel->getSignature('quote',(int)$id);
        $estimate['signature']=$sig?['signed'=>true,'signed_at'=>$sig['signed_at'],'signature_url'=>$sig['signature_url']]:['signed'=>false];
        return $this->respond(['status'=>true,'estimate'=>$estimate]);
    }

    public function clientRespond($id=null)
    {
        if(!$id) return $this->respond(['status'=>false,'message'=>'ID manquant'],400);
        $data=$this->request->getJSON(true);
        $contactId=(int)($data['contact_id']??0); $action=$data['action']??null;
        if(!$contactId||!in_array($action,['accept','decline'])) return $this->respond(['status'=>false,'message'=>'Paramètres invalides'],400);
        $contact=$this->db->table('tblcontacts')->select('userid,firstname,lastname,email')->where('id',$contactId)->get()->getRowArray();
        $estimate=$this->estimateModel->find((int)$id);
        if(!$contact||!$estimate||(int)$estimate['clientid']!==(int)$contact['userid']) return $this->respond(['status'=>false,'message'=>'Non autorisé'],403);
        if((int)$estimate['status']!==2) return $this->respond(['status'=>false,'message'=>'Ce devis ne peut plus être modifié'],400);

        $newStatus=$action==='accept'?4:3;
        $this->estimateModel->updateStatus((int)$id,$newStatus);
        if($action==='accept') $this->db->table('tblestimates')->where('id',(int)$id)->update([
            'acceptance_firstname'=>$contact['firstname']??'',
            'acceptance_lastname' =>$contact['lastname']??'',
            'acceptance_email'    =>$contact['email']??'',
            'acceptance_date'     =>date('Y-m-d H:i:s'),
        ]);
        return $this->respond(['status'=>true,'message'=>'Devis '.($action==='accept'?'accepté':'décliné'),'new_status'=>$newStatus,'status_label'=>$this->statuses[$newStatus]??'']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // HELPERS PRIVÉS
    // ═══════════════════════════════════════════════════════════════════════

    private function _copySignatureToInvoice(int $estimateId, int $invoiceId): void
    {
        try {
            $srcPng =ROOTPATH.'public/uploads/signatures/quote_'.$estimateId.'.png';
            $srcJson=ROOTPATH.'public/uploads/signatures/quote_'.$estimateId.'.json';
            if(!file_exists($srcPng)||!file_exists($srcJson)) return;
            $dstPng =ROOTPATH.'public/uploads/signatures/invoice_'.$invoiceId.'.png';
            $dstJson=ROOTPATH.'public/uploads/signatures/invoice_'.$invoiceId.'.json';
            copy($srcPng,$dstPng);
            $meta=json_decode(file_get_contents($srcJson),true);
            $meta['rel_type']='invoice'; $meta['rel_id']=$invoiceId;
            $meta['signature_file']='uploads/signatures/invoice_'.$invoiceId.'.png';
            file_put_contents($dstJson,json_encode($meta,JSON_PRETTY_PRINT));
        } catch(\Throwable $e){ log_message('error','_copySignatureToInvoice: '.$e->getMessage()); }
    }

    private function _createInvoice(array $estimate, array $items, array $data): ?int
    {
        $row=$this->db->table('tblinvoices')->selectMax('number')->get()->getRowArray();
        $number=(int)($row['number']??0)+1;
        $staffId=(int)($data['staff_id']??$estimate['addedfrom']??0);
        $subtotal=0.0; $totalTax=0.0;
        foreach($items as $item) {
            if(empty(trim($item['description']??''))) continue;
            $qty=(float)($item['qty']??1); $rate=(float)($item['rate']??0); $tax=(float)($item['taxrate']??0);
            $line=$qty*$rate; $subtotal+=$line; if($tax>0) $totalTax+=$line*$tax/100;
        }
        $dtype=$data['discount_type']??$estimate['discount_type']??'';
        $dpct=(float)($data['discount_percent']??$estimate['discount_percent']??0);
        $dbase=($dtype==='before_tax')?$subtotal:($subtotal+$totalTax);
        $dtotal=$dpct>0?round($dbase*$dpct/100,2):0.0;
        $total=round($subtotal+$totalTax-$dtotal,2);
        $this->db->table('tblinvoices')->insert([
            'sent'=>0,'datesend'=>null,'clientid'=>(int)$estimate['clientid'],
            'number'=>$number,'prefix'=>'INV-','number_format'=>1,
            'formatted_number'=>'INV-'.str_pad($number,6,'0',STR_PAD_LEFT),
            'datecreated'=>date('Y-m-d H:i:s'),'date'=>$data['date']??date('Y-m-d'),
            'duedate'=>$data['duedate']??date('Y-m-d',strtotime('+30 days')),
            'currency'=>(int)($data['currency']??$estimate['currency']??1),
            'subtotal'=>round($subtotal,2),'total_tax'=>round($totalTax,2),'total'=>$total,
            'adjustment'=>(float)($estimate['adjustment']??0),'addedfrom'=>$staffId,
            'sale_agent'=>(int)($data['sale_agent']??$estimate['sale_agent']??$staffId),
            'hash'=>md5(uniqid(rand(),true)),'status'=>1,
            'discount_percent'=>$dpct,'discount_total'=>$dtotal,'discount_type'=>$dtype,
            'recurring'=>0,'cancel_overdue_reminders'=>0,
            'terms'=>$estimate['terms']??null,'adminnote'=>$data['adminnote']??null,
            'billing_street'=>$data['billing_street']??$estimate['billing_street']??'',
            'billing_city'=>$data['billing_city']??$estimate['billing_city']??'',
            'billing_state'=>$data['billing_state']??$estimate['billing_state']??'',
            'billing_zip'=>$data['billing_zip']??$estimate['billing_zip']??'',
            'billing_country'=>(int)($data['billing_country']??$estimate['billing_country']??0),
            'include_shipping'=>(int)($data['include_shipping']??$estimate['include_shipping']??0),
            'show_shipping_on_invoice'=>1,
            'shipping_street'=>$data['shipping_street']??$estimate['shipping_street']??'',
            'shipping_city'=>$data['shipping_city']??$estimate['shipping_city']??'',
            'shipping_state'=>$data['shipping_state']??$estimate['shipping_state']??'',
            'shipping_zip'=>$data['shipping_zip']??$estimate['shipping_zip']??'',
            'shipping_country'=>(int)($data['shipping_country']??$estimate['shipping_country']??0),
            'show_quantity_as'=>1,'project_id'=>0,'subscription_id'=>0,'short_link'=>null,
        ]);
        $invoiceId=(int)$this->db->insertID();
        if(!$invoiceId) return null;
        foreach($items as $order=>$item) {
            if(empty(trim($item['description']??''))) continue;
            $this->db->table('tblitemable')->insert([
                'rel_id'=>$invoiceId,'rel_type'=>'invoice',
                'description'=>trim($item['description']??''),'long_description'=>trim($item['long_description']??''),
                'qty'=>(float)($item['qty']??1),'rate'=>(float)($item['rate']??0),
                'unit'=>$item['unit']??'','item_order'=>$order+1,'is_optional'=>(int)($item['is_optional']??0),'is_selected'=>1,
            ]);
        }
        return $invoiceId;
    }

    private function _generatePdfBytes(array $estimate): string
    {
        $id=$estimate['id']??0; $items=$estimate['items']??[]; $sym=$estimate['currency_symbol']??'';
        $numStr=$estimate['formatted_number']??('EST-'.str_pad($id,6,'0',STR_PAD_LEFT));
        $clientName=$estimate['client_company']??$estimate['clientname']??'';
        $agentName=$estimate['sale_agent_name']??''; $refNo=$estimate['reference_no']??'';
        $addressLine=implode(', ',array_filter([$estimate['billing_street']??'',$estimate['billing_city']??'',$estimate['billing_state']??'',$estimate['billing_zip']??''],fn($v)=>trim($v)!==''));

        $pdf=new TCPDF('P','mm','A4',true,'UTF-8',false);
        $pdf->setPrintHeader(false); $pdf->setPrintFooter(false);
        $pdf->SetCreator('CRM Mobile'); $pdf->SetTitle($numStr);
        $pdf->SetMargins(15,15,15); $pdf->SetAutoPageBreak(true,20); $pdf->AddPage();
        $pageW=210; $mL=15; $mR=15; $contentW=$pageW-$mL-$mR;

        $pdf->SetFont('helvetica','B',9); $pdf->SetTextColor(50,50,50);
        $pdf->SetXY($mL,15); $pdf->Cell($contentW,5,'À l\'attention de',0,1,'R');
        $pdf->SetXY($mL,$pdf->GetY()); $pdf->Cell($contentW,5,$clientName,0,1,'R');
        $pdf->SetFont('helvetica','',8); $pdf->SetTextColor(80,80,80);
        foreach(array_filter([$addressLine,$agentName?'Commercial : '.$agentName:'']) as $line)
            { $pdf->SetXY($mL,$pdf->GetY()); $pdf->Cell($contentW,4,$line,0,1,'R'); }

        $pdf->SetFont('helvetica','B',18); $pdf->SetTextColor(30,30,30);
        $pdf->SetXY($mL,$pdf->GetY()+4); $pdf->Cell(0,10,'# '.$numStr,0,1,'L');
        $pdf->SetFont('helvetica','',9); $pdf->SetTextColor(50,50,50);
        $pdf->SetXY($mL,$pdf->GetY()); $pdf->Cell(0,5,'Date : '.($estimate['date']??''),0,1,'L');
        $pdf->SetXY($mL,$pdf->GetY()); $pdf->Cell(0,5,'Valable jusqu\'au : '.($estimate['expirydate']??''),0,1,'L');
        if($refNo){ $pdf->SetXY($mL,$pdf->GetY()); $pdf->Cell(0,5,'Référence : '.$refNo,0,1,'L'); }

        $pdf->SetY($pdf->GetY()+4);
        $colW=[10,82,18,22,18,30]; $headers=['#','Désignation','Qté','P.U.','Taxe','Total']; $aligns=['C','L','C','R','C','R'];
        $pdf->SetFillColor(245,245,245); $pdf->SetFont('helvetica','B',9); $pdf->SetTextColor(50,50,50); $pdf->SetXY($mL,$pdf->GetY());
        foreach($headers as $hi=>$h) $pdf->Cell($colW[$hi],7,$h,'B',0,$aligns[$hi],true);
        $pdf->Ln();
        $rowNum=0; $pdf->SetFont('helvetica','',9);
        foreach($items as $item) {
            $rowNum++; $qty=(float)($item['qty']??1); $rate=(float)($item['rate']??0); $taxrate=(float)($item['taxrate']??0);
            $total=$qty*$rate; $qtyStr=($qty==floor($qty))?(string)(int)$qty:number_format($qty,2);
            $taxLbl=$taxrate>0?number_format($taxrate,0).'%':'0%';
            $fill=($rowNum%2===0)?[250,250,250]:[255,255,255];
            $pdf->SetFillColor($fill[0],$fill[1],$fill[2]); $yRow=$pdf->GetY();
            $pdf->SetXY($mL,$yRow); $pdf->Cell($colW[0],8,$rowNum,'B',0,'C',true);
            $pdf->SetFont('helvetica','B',9); $pdf->SetTextColor(30,30,30); $xItem=$pdf->GetX();
            $pdf->Cell($colW[1],8,'','B',0,'L',true);
            $pdf->MultiCell($colW[1],4,$item['description']??'',0,'L',false,0,$xItem,$yRow+2);
            $pdf->SetFont('helvetica','',9); $pdf->SetTextColor(50,50,50);
            $pdf->SetXY($mL+$colW[0]+$colW[1],$yRow);
            $pdf->Cell($colW[2],8,$qtyStr,'B',0,'C',true); $pdf->Cell($colW[3],8,$this->_fmtNum($rate),'B',0,'R',true);
            $pdf->Cell($colW[4],8,$taxLbl,'B',0,'C',true); $pdf->Cell($colW[5],8,$this->_fmtNum($total),'B',0,'R',true);
            $pdf->Ln();
        }

        $pdf->SetY($pdf->GetY()+2); $lW=40; $vW=30; $sX=$pageW-$mR-$lW-$vW;
        $totalsRows=[['Sous-total',(float)($estimate['subtotal']??0),false]];
        if((float)($estimate['total_tax']??0)>0) $totalsRows[]=['TVA',(float)$estimate['total_tax'],false];
        if((float)($estimate['discount_total']??0)>0) $totalsRows[]=['Remise',-(float)$estimate['discount_total'],false];
        $totalsRows[]=['Total TTC',(float)($estimate['total']??0),true];
        foreach($totalsRows as [$label,$val,$bold]) {
            $pdf->SetFillColor(245,245,245); $pdf->SetFont('helvetica',$bold?'B':'',9); $pdf->SetTextColor(50,50,50);
            $pdf->SetXY($sX,$pdf->GetY());
            $pdf->Cell($lW,6,$label,'',0,'R',$bold); $pdf->Cell($vW,6,$sym.$this->_fmtNum($val),'',1,'R',$bold);
        }

        $this->_appendSignatureBlock($pdf, 'quote', (int)$id, $mL, $contentW);

        return $pdf->Output('doc.pdf','S');
    }

    private function _appendSignatureBlock(
        TCPDF  $pdf,
        string $relType,
        int    $relId,
        float  $mL,
        float  $contentW,
        string $docTitle = ''
    ): void {
        if ($relId <= 0) return;
        $sig = $this->signatureModel->getSignature($relType, $relId);
        if (!$sig) return;

        $pngPath = ROOTPATH . 'public/uploads/signatures/' . $relType . '_' . $relId . '.png';

        $pdf->SetY($pdf->GetY() + 10);
        $pdf->SetDrawColor(220, 220, 220);
        $pdf->Line($mL, $pdf->GetY(), $mL + $contentW, $pdf->GetY());
        $pdf->SetY($pdf->GetY() + 8);

        if ($docTitle !== '') {
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->SetTextColor(30, 30, 30);
            $pdf->SetX($mL);
            $pdf->Cell($contentW, 6, $docTitle, 0, 1, 'C');
            $pdf->SetY($pdf->GetY() + 2);
        }

        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->SetX($mL);
        $pdf->Cell($contentW, 5, 'Authorized Signature', 0, 1, 'L');

        $tmpPath = file_exists($pngPath) ? $this->_rgbaPngToRgbPng($pngPath) : null;

        if ($tmpPath !== null) {
            $imgW = 60; $imgH = 20;
            $imgX = $mL + ($contentW - $imgW) / 2;
            try {
                $pdf->Image(
                    $tmpPath, $imgX, $pdf->GetY(),
                    $imgW, $imgH, 'PNG', '', 'N',
                    false, 300, '', false, false, 0, false, false, false
                );
                $pdf->SetY($pdf->GetY() + $imgH + 3);
            } catch (\Throwable $e) {
                log_message('error', 'PDF signature image: ' . $e->getMessage());
            } finally {
                if ($tmpPath !== $pngPath) @unlink($tmpPath);
            }
        }

        $signedAt = $sig['signed_at'] ?? '';
        if ($signedAt) {
            try {
                $dateLabel = 'Signed on: ' . (new \DateTime($signedAt))->format('d/m/Y');
            } catch (\Throwable $_) {
                $dateLabel = 'Signed on: ' . $signedAt;
            }
            $pdf->SetFont('helvetica', '', 8);
            $pdf->SetTextColor(120, 120, 120);
            $pdf->SetX($mL);
            $pdf->Cell($contentW, 5, $dateLabel, 0, 1, 'R');
        }
    }

    private function _rgbaPngToRgbPng(string $srcPath): ?string
    {
        $raw = @file_get_contents($srcPath);
        if (!$raw || strlen($raw) < 8) return null;
        if (substr($raw, 0, 8) !== "\x89PNG\r\n\x1a\n") return null;

        $pos = 8; $dataLen = strlen($raw);
        $W = $H = $bitDepth = $colorType = 0;
        $idatRaw = '';

        while ($pos + 12 <= $dataLen) {
            $cLen  = unpack('N', substr($raw, $pos, 4))[1];
            $cType = substr($raw, $pos + 4, 4);
            $cData = $cLen > 0 ? substr($raw, $pos + 8, $cLen) : '';
            $pos  += 4 + 4 + $cLen + 4;

            if ($cType === 'IHDR') {
                ['W' => $W, 'H' => $H, 'bit' => $bitDepth, 'color' => $colorType]
                    = unpack('NW/NH/Cbit/Ccolor', $cData);
                $W = (int)$W; $H = (int)$H; $bitDepth = (int)$bitDepth; $colorType = (int)$colorType;
            } elseif ($cType === 'IDAT') {
                $idatRaw .= $cData;
            } elseif ($cType === 'IEND') {
                break;
            }
        }

        if ($W <= 0 || $H <= 0) return null;
        if ($colorType === 2) return $srcPath;
        if ($colorType !== 6 || $bitDepth !== 8) return null;

        $inflated = @gzuncompress($idatRaw);
        if ($inflated === false) return null;

        $srcBpp    = 4;
        $srcStride = $W * $srcBpp;
        $prevLine  = str_repeat("\x00", $srcStride);
        $rgbLines  = '';
        $iPos      = 0;
        $infLen    = strlen($inflated);

        for ($y = 0; $y < $H; $y++) {
            if ($iPos >= $infLen) break;
            $filter  = ord($inflated[$iPos++]);
            $rawLine = ($iPos + $srcStride <= $infLen)
                ? substr($inflated, $iPos, $srcStride)
                : str_pad(substr($inflated, $iPos), $srcStride, "\x00");
            $iPos += $srcStride;

            $recon = '';
            for ($x = 0; $x < $srcStride; $x++) {
                $rb = ord($rawLine[$x]);
                $a  = $x >= $srcBpp ? ord($recon[$x - $srcBpp]) : 0;
                $b  = ord($prevLine[$x]);
                $c  = $x >= $srcBpp ? ord($prevLine[$x - $srcBpp]) : 0;
                switch ($filter) {
                    case 0: $v = $rb; break;
                    case 1: $v = ($rb + $a) & 0xFF; break;
                    case 2: $v = ($rb + $b) & 0xFF; break;
                    case 3: $v = ($rb + (int)(($a + $b) / 2)) & 0xFF; break;
                    case 4:
                        $p  = $a + $b - $c;
                        $pa = abs($p - $a); $pb = abs($p - $b); $pc = abs($p - $c);
                        $pr = ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
                        $v  = ($rb + $pr) & 0xFF; break;
                    default: $v = $rb; break;
                }
                $recon .= chr($v);
            }

            $rgbScanline = '';
            for ($x = 0; $x < $W; $x++) {
                $r = ord($recon[$x * 4]);
                $g = ord($recon[$x * 4 + 1]);
                $b = ord($recon[$x * 4 + 2]);
                $a = ord($recon[$x * 4 + 3]);
                $rgbScanline .= chr((int)(($r * $a + 255 * (255 - $a)) / 255))
                             .  chr((int)(($g * $a + 255 * (255 - $a)) / 255))
                             .  chr((int)(($b * $a + 255 * (255 - $a)) / 255));
            }
            $rgbLines .= "\x00" . $rgbScanline;
            $prevLine  = $recon;
        }

        $compressed = @gzcompress($rgbLines, 6);
        if ($compressed === false) return null;

        $chunk = static fn(string $t, string $d): string =>
            pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));

        $png  = "\x89PNG\r\n\x1a\n";
        $png .= $chunk('IHDR', pack('NNCCCCC', $W, $H, 8, 2, 0, 0, 0));
        $png .= $chunk('IDAT', $compressed);
        $png .= $chunk('IEND', '');

        $tmpPath = sys_get_temp_dir() . '/sig_rgb_' . uniqid('', true) . '.png';
        return @file_put_contents($tmpPath, $png) !== false ? $tmpPath : null;
    }

    private function _sendEstimateEmail(string $to, string $clientName, string $staffName, int $estimateId, array $estimate): bool
    {
        $numStr=$estimate['formatted_number']??('EST-'.str_pad($estimateId,6,'0',STR_PAD_LEFT));
        $html="<!DOCTYPE html><html><head><meta charset='UTF-8'><style>body{font-family:'Segoe UI',sans-serif;background:#f1f5f9;padding:20px}.box{max-width:600px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden}.hd{background:linear-gradient(135deg,#1e1b4b,#2563eb,#0ea5e9);padding:32px;text-align:center}.hd h2{color:#fff;margin:0;font-size:22px;font-weight:800}.bd{padding:32px}.note{background:#f0f9ff;border-left:4px solid #0ea5e9;padding:12px 16px;border-radius:0 8px 8px 0;color:#0369a1;font-size:13px;margin:16px 0}.ft{background:#f8fafc;padding:16px;text-align:center;color:#94a3b8;font-size:12px;border-top:1px solid #e2e8f0}</style></head><body><div class='box'><div class='hd'><h2>Devis $numStr</h2></div><div class='bd'><p>Bonjour <strong>".htmlspecialchars($clientName)."</strong>,</p><p><strong>".htmlspecialchars($staffName)."</strong> vous a transmis un devis :</p><p><strong style='font-size:16px'>$numStr</strong></p><div class='note'>Le PDF de votre devis est joint à cet email.</div><p style='color:#64748b;font-size:13px'>Pour toute question, contactez votre commercial.<br><strong>".htmlspecialchars($staffName)."</strong></p></div><div class='ft'>© ".date('Y')." — Envoyé automatiquement.</div></div></body></html>";
        try { $pdfBytes=$this->_generatePdfBytes($estimate); $pdfBase64=base64_encode($pdfBytes); }
        catch(\Throwable $e){ log_message('error','PDF gen: '.$e->getMessage()); $pdfBase64=null; }
        $payload=['sender'=>['name'=>'CRM Mobile','email'=>'ghoufranbensassy@gmail.com'],'to'=>[['email'=>$to,'name'=>$clientName]],'subject'=>"Devis $numStr",'htmlContent'=>$html];
        if($pdfBase64!==null) $payload['attachment']=[['name'=>'devis_'.$estimateId.'.pdf','content'=>$pdfBase64]];
        $ch=curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>json_encode($payload),
            CURLOPT_HTTPHEADER=>[
                'accept: application/json',
                'api-key: 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',
                'content-type: application/json',
            ],
            CURLOPT_TIMEOUT=>30,
        ]);
        $response=curl_exec($ch); // ← FIX: actually execute the request
        $httpCode=curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $err=curl_error($ch);
        curl_close($ch);
        if($err){ log_message('error','Brevo cURL: '.$err); return false; }
        return $httpCode===201;
    }

    private function _fmtNum(float $val): string
    {
        return number_format(abs($val), 2, ',', '.');
    }
}