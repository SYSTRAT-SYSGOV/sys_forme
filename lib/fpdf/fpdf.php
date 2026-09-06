<?php
/*******************************************************************************
* FPDF                                                                         *
* Version: 1.86                                                                *
* Author: Olivier PLATHEY                                                      *
*******************************************************************************/

define('FPDF_VERSION', '1.86');

#[\AllowDynamicProperties]
class FPDF
{
protected $page;               // current page number
protected $n;                  // current object number
protected $offsets;            // array of object offsets
protected $buffer;             // buffer holding in-memory PDF
protected $pages;              // array containing pages
protected $state;              // current document state
protected $isFinished;         // whether document is finished
protected $DefOrientation;     // default orientation
protected $CurOrientation;     // current orientation
protected $StdPageSizes;       // standard page sizes
protected $DefPageSize;        // default page size
protected $CurPageSize;        // current page size
protected $CurRotation;        // current page rotation
protected $PageInfo;           // page-related data
protected $wPt, $hPt;          // dimensions of page in points
protected $w, $h;              // dimensions of page in user unit
protected $lMargin;            // left margin
protected $tMargin;            // top margin
protected $rMargin;            // right margin
protected $bMargin;            // page break margin
protected $cMargin;            // cell margin
protected $x, $y;              // current position in user unit
protected $lasth;              // height of last printed cell
protected $LineWidth;          // line width in user unit
protected $fontpath;           // path containing fonts
protected $CoreFonts;          // array of core font names
protected $fonts;              // array of used fonts
protected $FontFiles;          // array of font files
protected $encodings;          // array of encodings
protected $cmaps;              // array of ToUnicode CMaps
protected $FontFamily;         // current font family
protected $FontStyle;          // current font style
protected $underline;          // underlining flag
protected $CurrentFont;        // current font info
protected $FontSizePt;         // current font size in points
protected $FontSize;           // current font size in user unit
protected $DrawColor;          // commands for drawing color
protected $FillColor;          // commands for filling color
protected $TextColor;          // commands for text color
protected $ColorFlag;          // whether fill and text colors are different
protected $WithAlpha;          // whether alpha channel is used
protected $ws;                 // word spacing
protected $images;             // array of used images
protected $PageLinks;          // array of links in pages
protected $links;              // array of internal links
protected $AutoPageBreak;      // automatic page breaking
protected $PageBreakTrigger;   // threshold causing page break
protected $InHeader;           // flag set when processing header
protected $InFooter;           // flag set when processing footer
protected $AliasNbPages;       // alias for total number of pages
protected $ZoomMode;           // zoom display mode
protected $LayoutMode;         // layout display mode
protected $metadata;           // document properties
protected $PDFVersion;         // PDF version number

function __construct($orientation='P', $unit='mm', $size='A4')
{
	// Some initialization
	$this->_dochecks();
	// Initialization of properties
	$this->state = 0;
	$this->page = 0;
	$this->n = 2;
	$this->buffer = '';
	$this->pages = array();
	$this->PageInfo = array();
	$this->fonts = array();
	$this->FontFiles = array();
	$this->encodings = array();
	$this->cmaps = array();
	$this->images = array();
	$this->links = array();
	$this->InHeader = false;
	$this->InFooter = false;
	$this->AliasNbPages = '{nb}';
	$this->ZoomMode = 'fullwidth';
	$this->LayoutMode = 'continuous';
	$this->metadata = array('Title'=>'', 'Subject'=>'', 'Author'=>'', 'Keywords'=>'', 'Creator'=>'FPDF');
	$this->CoreFonts = array('courier', 'helvetica', 'times', 'symbol', 'zapfdingbats');
	// Page size management
	$this->StdPageSizes = array('a3'=>array(841.89, 1190.55), 'a4'=>array(595.28, 841.89), 'a5'=>array(420.94, 595.28),
		'letter'=>array(612, 792), 'legal'=>array(612, 1008));
	$size = $this->_getpagesize($size);
	$this->DefPageSize = $size;
	$this->CurPageSize = $size;
	// Page orientation
	$orientation = strtolower($orientation);
	if($orientation=='p' || $orientation=='portrait')
	{
		$this->DefOrientation = 'P';
		$this->w = $size[0];
		$this->h = $size[1];
	}
	elseif($orientation=='l' || $orientation=='landscape')
	{
		$this->DefOrientation = 'L';
		$this->w = $size[1];
		$this->h = $size[0];
	}
	else
		$this->Error('Incorrect orientation: '.$orientation);
	$this->CurOrientation = $this->DefOrientation;
	$this->wPt = $this->w*72/25.4;
	$this->hPt = $this->h*72/25.4;
	// Page margins
	$margin = 28.35/2.835;
	$this->SetMargins($margin, $margin);
	// Interior cell margin (1 mm)
	$this->cMargin = 1;
	// Line width (0.2 mm)
	$this->LineWidth = .567/2.835;
	// Automatic page break
	$this->SetAutoPageBreak(true, 2*$margin);
	// Full width display mode
	$this->SetDisplayMode('fullwidth');
	// Enable compression
	$this->PDFVersion = '1.3';
}

function SetMargins($left, $top, $right=null)
{
	$this->lMargin = $left;
	$this->tMargin = $top;
	if($right===null)
		$right = $left;
	$this->rMargin = $right;
}

function SetLeftMargin($margin)
{
	$this->lMargin = $margin;
	if($this->page>0 && $this->x<$margin)
		$this->x = $margin;
}

function SetTopMargin($margin)
{
	$this->tMargin = $margin;
}

function SetRightMargin($margin)
{
	$this->rMargin = $margin;
}

function SetAutoPageBreak($auto, $margin=0)
{
	$this->AutoPageBreak = $auto;
	$this->bMargin = $margin;
	$this->PageBreakTrigger = $this->h - $margin;
}

function SetDisplayMode($zoom, $layout='continuous')
{
	if($zoom=='fullpage' || $zoom=='fullwidth' || $zoom=='real' || $zoom=='default' || !is_string($zoom))
		$this->ZoomMode = $zoom;
	else
		$this->Error('Incorrect zoom display mode: '.$zoom);
	if($layout=='single' || $layout=='continuous' || $layout=='two' || $layout=='default')
		$this->LayoutMode = $layout;
	else
		$this->Error('Incorrect layout display mode: '.$layout);
}

function SetTitle($title, $isUTF8=false)
{
	$this->metadata['Title'] = $isUTF8 ? $title : utf8_encode($title);
}

function SetSubject($subject, $isUTF8=false)
{
	$this->metadata['Subject'] = $isUTF8 ? $subject : utf8_encode($subject);
}

function SetAuthor($author, $isUTF8=false)
{
	$this->metadata['Author'] = $isUTF8 ? $author : utf8_encode($author);
}

function Error($msg)
{
	throw new Exception('FPDF error: '.$msg);
}

function Open()
{
	$this->state = 1;
}

function Close()
{
	if($this->state==3)
		return;
	if($this->page==0)
		$this->AddPage();
	// Page footer
	$this->InFooter = true;
	$this->Footer();
	$this->InFooter = false;
	// Close page
	$this->_endpage();
	// Close document
	$this->_enddoc();
}

function AddPage($orientation='', $size='', $rotation=0)
{
	if($this->state==0)
		$this->Open();
	$family = $this->FontFamily;
	$style = $this->FontStyle.($this->underline ? 'U' : '');
	$fontsize = $this->FontSizePt;
	$lw = $this->LineWidth;
	$dc = $this->DrawColor;
	$fc = $this->FillColor;
	$tc = $this->TextColor;
	$cf = $this->ColorFlag;
	if($this->page>0)
	{
		// Page footer
		$this->InFooter = true;
		$this->Footer();
		$this->InFooter = false;
		// Close page
		$this->_endpage();
	}
	// Start new page
	$this->_beginpage($orientation, $size, $rotation);
	// Set line cap style to square
	$this->_out('2 J');
	// Set line width
	$this->LineWidth = $lw;
	$this->_out(sprintf('%.2F w', $lw*72/25.4));
	// Set font
	if($family)
		$this->SetFont($family, $style, $fontsize);
	// Set colors
	$this->DrawColor = $dc;
	if($dc!='0 G')
		$this->_out($dc);
	$this->FillColor = $fc;
	if($fc!='0 g')
		$this->_out($fc);
	$this->TextColor = $tc;
	$this->ColorFlag = $cf;
	// Page header
	$this->InHeader = true;
	$this->Header();
	$this->InHeader = false;
	// Restore line width
	if($this->LineWidth!=$lw)
	{
		$this->LineWidth = $lw;
		$this->_out(sprintf('%.2F w', $lw*72/25.4));
	}
	// Restore font
	if($family)
		$this->SetFont($family, $style, $fontsize);
	// Restore colors
	if($this->DrawColor!=$dc)
	{
		$this->DrawColor = $dc;
		$this->_out($dc);
	}
	if($this->FillColor!=$fc)
	{
		$this->FillColor = $fc;
		$this->_out($fc);
	}
	$this->TextColor = $tc;
	$this->ColorFlag = $cf;
}

function Header() {}
function Footer() {}

function PageNo()
{
	return $this->page;
}

function SetDrawColor($r, $g=null, $b=null)
{
	if(($r==0 && $g==0 && $b==0) || $g===null)
		$this->DrawColor = sprintf('%.3F G', $r/255);
	else
		$this->DrawColor = sprintf('%.3F %.3F %.3F RG', $r/255, $g/255, $b/255);
	if($this->page>0)
		$this->_out($this->DrawColor);
}

function SetFillColor($r, $g=null, $b=null)
{
	if(($r==0 && $g==0 && $b==0) || $g===null)
		$this->FillColor = sprintf('%.3F g', $r/255);
	else
		$this->FillColor = sprintf('%.3F %.3F %.3F rg', $r/255, $g/255, $b/255);
	$this->ColorFlag = ($this->FillColor!=$this->TextColor);
	if($this->page>0)
		$this->_out($this->FillColor);
}

function SetTextColor($r, $g=null, $b=null)
{
	if(($r==0 && $g==0 && $b==0) || $g===null)
		$this->TextColor = sprintf('%.3F g', $r/255);
	else
		$this->TextColor = sprintf('%.3F %.3F %.3F rg', $r/255, $g/255, $b/255);
	$this->ColorFlag = ($this->FillColor!=$this->TextColor);
}

function GetStringWidth($s)
{
	// Get width of a string in the current font
	$s = (string)$s;
	$cw = &$this->CurrentFont['cw'];
	$w = 0;
	$l = strlen($s);
	for($i=0;$i<$l;$i++)
		$w += $cw[$s[$i]] ?? 600;
	return $w*$this->FontSize/1000;
}

function SetLineWidth($width)
{
	$this->LineWidth = $width;
	if($this->page>0)
		$this->_out(sprintf('%.2F w', $width*72/25.4));
}

function Line($x1, $y1, $x2, $y2)
{
	$this->_out(sprintf('%.2F %.2F m %.2F %.2F l S', $x1*72/25.4, ($this->h-$y1)*72/25.4, $x2*72/25.4, ($this->h-$y2)*72/25.4));
}

function Rect($x, $y, $w, $h, $style='')
{
	if($style=='F')
		$op = 'f';
	elseif($style=='FD' || $style=='DF')
		$op = 'B';
	else
		$op = 'S';
	$this->_out(sprintf('%.2F %.2F %.2F %.2F re %s', $x*72/25.4, ($this->h-$y)*72/25.4, $w*72/25.4, -$h*72/25.4, $op));
}

function SetFont($family, $style='', $size=0)
{
	// Select a font; size given in points
	if($family=='')
		$family = $this->FontFamily;
	else
		$family = strtolower($family);
	$style = strtoupper($style);
	if(strpos($style, 'U')!==false)
	{
		$this->underline = true;
		$style = str_replace('U', '', $style);
	}
	else
		$this->underline = false;
	if($style=='IB')
		$style = 'BI';
	if($size==0)
		$size = $this->FontSizePt;
	// Test if font is already selected
	if($this->FontFamily==$family && $this->FontStyle==$style && $this->FontSizePt==$size)
		return;
	
	// Default to helvetica if not core
	if(!in_array($family, $this->CoreFonts))
		$family = 'helvetica';
	
	$fontkey = $family.$style;
	if(!isset($this->fonts[$fontkey]))
	{
		// Basic built-in standard font setup
		$this->fonts[$fontkey] = array('i'=>count($this->fonts)+1, 'type'=>'core', 'name'=>$family, 'up'=>-100, 'ut'=>50, 'cw'=>array());
		for($i=0;$i<256;$i++) $this->fonts[$fontkey]['cw'][chr($i)] = 600;
	}
	$this->FontFamily = $family;
	$this->FontStyle = $style;
	$this->FontSizePt = $size;
	$this->FontSize = $size/72*25.4;
	$this->CurrentFont = &$this->fonts[$fontkey];
	if($this->page>0)
		$this->_out(sprintf('BT /F%d %.2F Tf ET', $this->CurrentFont['i'], $this->FontSizePt));
}

function SetFontSize($size)
{
	if($this->FontSizePt==$size)
		return;
	$this->FontSizePt = $size;
	$this->FontSize = $size/72*25.4;
	if($this->page>0)
		$this->_out(sprintf('BT /F%d %.2F Tf ET', $this->CurrentFont['i'], $this->FontSizePt));
}

function Cell($w, $h=0, $txt='', $border=0, $ln=0, $align='', $fill=false, $link='')
{
	// Output a cell
	$k = 72/25.4;
	if($this->y+$h>$this->PageBreakTrigger && !$this->InHeader && !$this->InFooter && $this->AcceptPageBreak())
	{
		$x = $this->x;
		$ws = $this->ws;
		if($ws>0)
		{
			$this->ws = 0;
			$this->_out('0 Tw');
		}
		$this->AddPage($this->CurOrientation, $this->CurPageSize, $this->CurRotation);
		$this->x = $x;
		if($ws>0)
		{
			$this->ws = $ws;
			$this->_out(sprintf('%.3F Tw', $ws*$k));
		}
	}
	if($w==0)
		$w = $this->w-$this->rMargin-$this->x;
	$s = '';
	if($fill || $border==1)
	{
		if($fill)
			$op = ($border==1) ? 'B' : 'f';
		else
			$op = 'S';
		$s .= sprintf('%.2F %.2F %.2F %.2F re %s ', $this->x*$k, ($this->h-$this->y)*$k, $w*$k, -$h*$k, $op);
	}
	if(is_string($border))
	{
		$x = $this->x;
		$y = $this->y;
		if(strpos($border, 'L')!==false)
			$s .= sprintf('%.2F %.2F m %.2F %.2F l S ', $x*$k, ($this->h-$y)*$k, $x*$k, ($this->h-($y+$h))*$k);
		if(strpos($border, 'T')!==false)
			$s .= sprintf('%.2F %.2F m %.2F %.2F l S ', $x*$k, ($this->h-$y)*$k, ($x+$w)*$k, ($this->h-$y)*$k);
		if(strpos($border, 'R')!==false)
			$s .= sprintf('%.2F %.2F m %.2F %.2F l S ', ($x+$w)*$k, ($this->h-$y)*$k, ($x+$w)*$k, ($this->h-($y+$h))*$k);
		if(strpos($border, 'B')!==false)
			$s .= sprintf('%.2F %.2F m %.2F %.2F l S ', $x*$k, ($this->h-($y+$h))*$k, ($x+$w)*$k, ($this->h-($y+$h))*$k);
	}
	if($txt!=='')
	{
		$txt = (string)$txt;
		if($align=='R')
			$dx = $w-$this->cMargin-$this->GetStringWidth($txt);
		elseif($align=='C')
			$dx = ($w-$this->GetStringWidth($txt))/2;
		else
			$dx = $this->cMargin;
		if($this->ColorFlag)
			$s .= 'q '.$this->TextColor.' ';
		$txt2 = str_replace(')', '\\)', str_replace('(', '\\(', str_replace('\\', '\\\\', $txt)));
		$s .= sprintf('BT %.2F %.2F Td (%s) Tj ET', ($this->x+$dx)*$k, ($this->h-($this->y+.5*$h+.3*$this->FontSize))*$k, $txt2);
		if($this->ColorFlag)
			$s .= ' Q';
	}
	if($s)
		$this->_out($s);
	$this->lasth = $h;
	if($ln>0)
	{
		$this->y += $h;
		if($ln==1)
			$this->x = $this->lMargin;
	}
	else
		$this->x += $w;
}

function MultiCell($w, $h, $txt, $border=0, $align='J', $fill=false)
{
	$cw = &$this->CurrentFont['cw'];
	if($w==0)
		$w = $this->w-$this->rMargin-$this->x;
	$wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;
	$s = str_replace("\r", '', (string)$txt);
	$nb = strlen($s);
	if($nb>0 && $s[$nb-1]=="\n")
		$nb--;
	$b = 0;
	if($border)
	{
		if($border==1)
		{
			$border = 'LTRB';
			$b = 'LRT';
			$b2 = 'LR';
		}
		else
		{
			$b2 = '';
			if(strpos($border, 'L')!==false) $b2 .= 'L';
			if(strpos($border, 'R')!==false) $b2 .= 'R';
			$b = strpos($border, 'T')!==false ? $b2.'T' : $b2;
		}
	}
	$sep = -1;
	$i = 0;
	$j = 0;
	$l = 0;
	$ns = 0;
	$nl = 1;
	while($i<$nb)
	{
		$c = $s[$i];
		if($c=="\n")
		{
			$this->Cell($w, $h, substr($s, $j, $i-$j), $b, 2, $align, $fill);
			$i++;
			$sep = -1;
			$j = $i;
			$l = 0;
			$ns = 0;
			$nl++;
			if($border && $nl==2)
				$b = $b2;
			continue;
		}
		if($c==' ')
		{
			$sep = $i;
			$ls = $l;
			$ns++;
		}
		$l += $cw[$c] ?? 600;
		if($l>$wmax)
		{
			if($sep==-1)
			{
				if($i==$j)
					$i++;
				$this->Cell($w, $h, substr($s, $j, $i-$j), $b, 2, $align, $fill);
			}
			else
			{
				$this->Cell($w, $h, substr($s, $j, $sep-$j), $b, 2, $align, $fill);
				$i = $sep+1;
			}
			$sep = -1;
			$j = $i;
			$l = 0;
			$ns = 0;
			$nl++;
			if($border && $nl==2)
				$b = $b2;
		}
		else
			$i++;
	}
	if($border && strpos($border, 'B')!==false)
		$b .= 'B';
	$this->Cell($w, $h, substr($s, $j, $i-$j), $b, 2, $align, $fill);
	$this->x = $this->lMargin;
}

function Ln($h=null)
{
	$this->x = $this->lMargin;
	if($h===null)
		$this->y += $this->lasth;
	else
		$this->y += $h;
}

function GetX() { return $this->x; }
function SetX($x) { if($x>=0) $this->x = $x; else $this->x = $this->w+$x; }
function GetY() { return $this->y; }
function SetY($y, $resetX=true) { if($y>=0) $this->y = $y; else $this->y = $this->h+$y; if($resetX) $this->x = $this->lMargin; }
function SetXY($x, $y) { $this->SetY($y, false); $this->SetX($x); }

function Output($dest='', $name='', $isUTF8=false)
{
	$this->Close();
	if(empty($dest))
		$dest = 'I';
	if(empty($name))
		$name = 'recibo.pdf';
	switch(strtoupper($dest))
	{
		case 'I':
			header('Content-Type: application/pdf');
			header('Content-Disposition: inline; filename="'.$name.'"');
			header('Cache-Control: private, max-age=0, must-revalidate');
			header('Pragma: public');
			echo $this->buffer;
			break;
		case 'D':
			header('Content-Type: application/x-download');
			header('Content-Disposition: attachment; filename="'.$name.'"');
			header('Cache-Control: private, max-age=0, must-revalidate');
			header('Pragma: public');
			echo $this->buffer;
			break;
		case 'S':
			return $this->buffer;
		default:
			$this->Error('Incorrect output destination: '.$dest);
	}
	return '';
}

protected function _dochecks() {}
protected function _getpagesize($size)
{
	if(is_string($size))
	{
		$size = strtolower($size);
		if(!isset($this->StdPageSizes[$size]))
			$this->Error('Unknown page size: '.$size);
		$a = $this->StdPageSizes[$size];
		return array($a[0]/72*25.4, $a[1]/72*25.4);
	}
	else
		return array($size[0], $size[1]);
}
protected function _beginpage($orientation, $size, $rotation)
{
	$this->page++;
	$this->pages[$this->page] = '';
	$this->state = 2;
	$this->x = $this->lMargin;
	$this->y = $this->tMargin;
	$this->FontFamily = '';
	if($orientation=='')
		$orientation = $this->DefOrientation;
	else
		$orientation = strtoupper($orientation[0]);
	if($size=='')
		$size = $this->DefPageSize;
	else
		$size = $this->_getpagesize($size);
	if($orientation!=$this->CurOrientation || $size[0]!=$this->CurPageSize[0] || $size[1]!=$this->CurPageSize[1])
	{
		if($orientation=='P')
		{
			$this->w = $size[0];
			$this->h = $size[1];
		}
		else
		{
			$this->w = $size[1];
			$this->h = $size[0];
		}
		$this->wPt = $this->w*72/25.4;
		$this->hPt = $this->h*72/25.4;
		$this->PageBreakTrigger = $this->h-$this->bMargin;
		$this->CurOrientation = $orientation;
		$this->CurPageSize = $size;
	}
}
protected function _endpage()
{
	$this->state = 1;
}
protected function _enddoc()
{
	$this->_putheader();
	$this->_putpages();
	$this->_putresources();
	$this->_putinfo();
	$this->_putcatalog();
	$this->_puttrailer();
	$this->state = 3;
}
protected function _out($s)
{
	if($this->state==2)
		$this->pages[$this->page] .= $s."\n";
	else
		$this->buffer .= $s."\n";
}
protected function _putheader()
{
	$this->_out('%PDF-'.$this->PDFVersion);
}
protected function _putpages()
{
	$nb = $this->page;
	for($n=1;$n<=$nb;$n++)
		$this->PageInfo[$n]['n'] = $this->n + $n;
	for($n=1;$n<=$nb;$n++)
	{
		$this->_newobj();
		$this->_out('<</Type /Page');
		$this->_out('/Parent 1 0 R');
		$this->_out(sprintf('/MediaBox [0 0 %.2F %.2F]', $this->wPt, $this->hPt));
		$this->_out('/Resources 2 0 R');
		$this->_out('/Contents '.($this->n+1).' 0 R>>');
		$this->_out('endobj');
		
		// Page content
		$p = $this->pages[$n];
		$this->_newobj();
		$this->_out('<</Length '.strlen($p).'>>');
		$this->_out('stream');
		$this->_out($p);
		$this->_out('endstream');
		$this->_out('endobj');
	}
	// Pages root
	$this->offsets[1] = strlen($this->buffer);
	$this->buffer .= "1 0 R\n<</Type /Pages\n/Kids [";
	for($n=1;$n<=$nb;$n++)
		$this->buffer .= $this->PageInfo[$n]['n']." 0 R ";
	$this->buffer .= "]\n/Count ".$nb."\n>>\nendobj\n";
}
protected function _putresources()
{
	$this->_newobj(2);
	$this->_out('<</ProcSet [/PDF /Text]');
	$this->_out('/Font <<');
	foreach($this->fonts as $font)
		$this->_out('/F'.$font['i'].' <</Type /Font /Subtype /Type1 /BaseFont /'.ucfirst($font['name']).' /Encoding /WinAnsiEncoding>>>>');
	$this->_out('>>>>');
	$this->_out('endobj');
}
protected function _putinfo()
{
	$this->_newobj();
	$this->_out('<<');
	foreach($this->metadata as $key=>$value)
		$this->_out('/'.$key.' ('.$value.')');
	$this->_out('/CreationDate (D:'.date('YmdHis').')');
	$this->_out('>>');
	$this->_out('endobj');
}
protected function _putcatalog()
{
	$this->_newobj();
	$this->_out('<</Type /Catalog /Pages 1 0 R>>');
	$this->_out('endobj');
}
protected function _puttrailer()
{
	$this->_out('xref');
	$this->_out('0 '.($this->n+1));
	$this->_out('0000000000 65535 f ');
	for($i=1;$i<=$this->n;$i++)
		$this->_out(sprintf('%010d 00000 n ', $this->offsets[$i] ?? 0));
	$this->_out('trailer');
	$this->_out('<</Size '.($this->n+1).' /Root '.$this->n.' 0 R /Info '.($this->n-1).' 0 R>>');
	$this->_out('startxref');
	$this->_out(strlen($this->buffer));
	$this->_out('%%EOF');
}
protected function _newobj($n=null)
{
	if($n===null)
		$n = ++$this->n;
	$this->offsets[$n] = strlen($this->buffer);
	$this->_out($n.' 0 obj');
}
}
