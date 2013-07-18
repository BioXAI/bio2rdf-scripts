<?php
/**
Copyright (C) 2011-2013 Michel Dumontier

Permission is hereby granted, free of charge, to any person obtaining a copy of
this software and associated documentation files (the "Software"), to deal in
the Software without restriction, including without limitation the rights to
use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies
of the Software, and to permit persons to whom the Software is furnished to do
so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
*/

/**
 * An RDF generator for iRefIndex (http://irefindex.uio.no)
 * documentation: http://irefindex.uio.no/wiki/README_MITAB2.6_for_iRefIndex_9.0
 * @version 2.0
 * @author Michel Dumontier
*/

require_once(__DIR__.'/../../php-lib/bio2rdfapi.php');
class irefindexParser extends Bio2RDFizer 
{
	function __construct($argv) { //
		parent::__construct($argv,"irefindex");
		parent::addParameter('files',true,'all|10090|10116|4932|559292|562|6239|7227|9606|other','all','all or comma-separated list of files to process');
		parent::addParameter('version',false,null,'10182011'/*'03022013'*/,'dated version of files to download');
		parent::addParameter('download_url',false,null,'ftp://ftp.no.embnet.org/irefindex/data/current/psi_mitab/MITAB2.6/');
		parent::initialize();
	}
	
	function Run()
	{
		// get the file list
		if(parent::getParameterValue('files') == 'all') {
			$files = array('all');
		} else {
			$files = explode(",",parent::getParameterValue('files'));
		}

		$ldir = parent::getParameterValue('indir');
		$odir = parent::getParameterValue('outdir');
		$rdir = parent::getParameterValue('download_url');
		
		foreach($files AS $file) {
			$download = parent::getParameterValue('download');
			$base_file = ucfirst($file).".mitab.".parent::getParameterValue("version").".txt";
			$zip_file  = $base_file.".zip";
			$lfile = $ldir.$zip_file;
			
			$ofile = "irefindex-".$file.".".parent::getParameterValue('output_format');
			$gz = (strstr(parent::getParameterValue('output_format'),".gz") === FALSE)?false:true;			
			$download_files[] = $ofile;
			
			if(!file_exists($lfile)) {
				trigger_error($lfile." not found. Will attempt to download.", E_USER_NOTICE);
				$download = true;
			}
			
			if($download == true) {
				if(FALSE === Utils::Download("ftp://ftp.no.embnet.org",array("/irefindex/data/current/psi_mitab/MITAB2.6/".$zip_file),$ldir)) {
					trigger_error("Error in Download");
					return FALSE;
				}
			}
			
			$zin = new ZipArchive();
			if ($zin->open($lfile) === FALSE) {
				trigger_error("Unable to open $lfile");
				exit;
			}
			if(($fp = $zin->getStream($base_file)) === FALSE) {
					trigger_error("Unable to get $base_file in ziparchive $lfile");
					return FALSE;
			}
			parent::setReadFile($lfile);
			parent::getReadFile()->setFilePointer($fp);
				
			echo "Processing ".$file." ...";
			parent::setWriteFile($odir.$ofile, true);
	
			if($this->Parse() === FALSE) {
				trigger_error("Parsing Error");
				exit;
			}
			
			parent::writeRDFBufferToWriteFile();
			parent::getWriteFile()->close();
			$zin->close();
			echo "Done!".PHP_EOL;
		}
		
		// generate the release file
		$desc = parent::getBio2RDFDatasetDescription(
			parent::getPrefix(),
			"https://github.com/bio2rdf/bio2rdf-scripts/blob/master/irefindex/irefindex.php", 
			$download_files,
			"http://irefindex.uio.no", 
			array("use","attribution","no-commercial"), 
			"http://irefindex.uio.no/wiki/README_MITAB2.6_for_iRefIndex#License",
			parent::getParameterValue('download_url'),
			parent::getDatasetVersion()
		);
		parent::setWriteFile($odir.parent::getBio2RDFReleaseFile(parent::getPrefix()));
		parent::getWriteFile()->write($desc);
		parent::getWriteFile()->close();
		
		return TRUE;
	}

	function Parse()
	{
		$l = parent::getReadFile()->read(100000);
		$header = explode("\t",trim(substr($l,1)));
		if(($c = count($header)) != 54) {
			trigger_erorr("Expecting 54 columns, found $c!");
			return FALSE;
		}

		// check # of columns
		while($l = parent::getReadFile()->read(100000)) {
			$a = explode("\t",trim($l));
print_r($a);
			// 13 is the original identifier
			$ids = explode("|",$a[13],2);
			parent::getRegistry()->parseQName($ids[0],$ns,$str);
			
			$this->Parse4IDLabel($str,$id,$label);
			$id = str_replace('"','',$id);
			$iid = "$ns:$id";
			print_r($iid);exit;

			// get the type
			if($a[52] == "X") {
				$label = "Pairwise interaction between $a[0] and $a[1]";
				$type = "Pairwise-Interaction";
			} else if($a[52] == "C") {
				$label = $a[53]." component complex";
				$type = "Multimeric-Complex";
			} else if($a[52] == "Y") {
				$label = "homomeric complex composed of $a[0]";  
				$type = "Homopolymeric-Complex";
			}

			// generate the label
			// interaction type[52] by method[6]
			if($a[6] != '-') {
				$qname = $this->ParseString($a[6],$ns,$id,$method);
				if($qname) parent::addRDF(parent::triplify($iid,parent::getVoc()."method",$qname));
			}

			$method_label = '';
			if($method != 'NA' && $method != '-1') $method_label = " identified by $method ";
			parent::addRDF(
				parent::describeIndividual($iid,$label.$method_label,parent::getVoc().$type)
			);
			
			parent::addRDF(
				parent::QQuadO_URL($iid,"rdfs:seeAlso","http://wodaklab.org/iRefWeb/interaction/show/".$a[50])
			);

			// set the interators
			for($i=0;$i<=1;$i++) {
				$p = 'a';
				if($i == 1) $p = 'b';

				$interactor = $this->ParseString($a[$i],$ns,$id,$label);
				parent::addRDF(
					parent::triplify($iid,parent::getVoc()."interactor_$p",$interactor)
				);

				// biological role
				$role = $a[16+$i];
				if($role != '-') {
					$qname = $this->ParseString($role,$ns,$id,$label);
					if($qname != "mi:0000") {
						parent::addRDF(
							parent::triplify($iid,parent::getVoc()."interactor_$p"."_biological_role",$qname)
						);
					}
				}
				// experimental role
				$role = $a[18+$i];
				if($role != '-') {
					$qname = $this->ParseString($role,$ns,$id,$label);
					if($qname != "mi:0000") {
						parent::addRDF(
							parent::triplify($iid,parent::getVoc()."interactor_$p"."_experimental_role",$qname)
						);
					}
				}
				// interactor type
				$type = $a[20+$i];
				if($type != '-') {
					$qname = $this->ParseString($type,$ns,$id,$label);
					parent::addRDF(
						parent::triplify($interactor,"rdf:type",$qname)
					);
				}
			}

			// add the alternatives through the taxon + seq redundant group
			for($i=2;$i<=3;$i++) {
				$taxid = '';
				$irogid = "irefindex_irogid:".$a[42+($i-2)];
				if(!isset($defined[$irogid])) {
					$defined[$irogid] = '';
					parent::addRDF(
						parent::describeIndividual($irogid,"",parent::getVoc()."Taxon-Sequence-Identical-Group")
					);
					$tax = $a[9+($i-2)];
					if($tax && $tax != '-' && $tax != '-1') {
						$taxid = $this->ParseString($tax,$ns,$id,$label);
						parent::addRDF(
							parent::triplify($irogid,parent::getVoc()."x-taxonomy",$taxid)
						);
					}
				}

				$list = explode("|",$a[3]);
				foreach($list AS $item) {
					$qname = $this->ParseString($item,$ns,$id,$label);
					if($ns && $ns != 'irefindex_rogid' && $ns != 'irefindex_irogid') {
						parent::addRDF(
							parent::triplify($qname,parent::getVoc()."taxon-sequence-identical-group",$irogid)
						);	
						if($taxid && $taxid != '-' && $taxid != '-1') parent::addRDF(
							parent::triplify($qname,parent::getVoc()."x-taxonomy",$taxid)
						);
					}
				}
			}	
			// add the aliases through the canonical group
			for($i=4;$i<=5;$i++) {
				$icrogid = "irefindex_icrogid:".$a[49+($i-4)];
				if(!isset($defined[$icrogid])) {
					$defined[$icrogid] = '';
					parent::addRDF(
						parent::describeIndividual($icrogid, "",parent::getVoc()."Taxon-Sequence-Similar-Group")
					);
				}

				$list = explode("|",$a[3]);
				foreach($list AS $item) {
					$qname = $this->ParseString($item,$ns,$id,$label);
					if($ns && $ns != 'crogid' && $ns != 'icrogid') {
						parent::addRDF(
							parent::triplify($qname,parent::getVoc()."taxon-sequence-similar-group",$icrogid)
						);	
					}
				}
			}

			// publications
			$list = explode("|",$a[8]);
			foreach($list AS $item) {
				if($item == '-' && $item != 'pubmed:0') continue;
				$qname = $this->ParseString($item,$ns,$id,$label);
				parent::addRDF(
					parent::triplify($iid,parent::getVoc()."article",$qname)
				);
			}
			
			// MI interaction type
			if($a[11] != '-' && $a[11] != 'NA') {
				$qname = $this->ParseString($a[11],$ns,$id,$label);
				parent::addRDF(parent::triplify($iid,"rdf:type",$qname));
				if(!isset($defined[$qname])) {
					$defined[$qname] = '';
					parent::addRDF(
						parent::triplifyString($qname,"rdfs:label",$label)
					);
				}
			}
			
			// source
			if($a[12] != '-') {
				$qname = $this->ParseString($a[12],$ns,$id,$label);
				parent::addRDF(
					parent::triplify($iid,parent::getVoc()."source",$qname)
				);
			}
		
			// confidence
			$list = explode("|",$a[14]);
			foreach($list AS $item) {
				$this->ParseString($item,$ns,$id,$label);
				if($ns == 'lpr') {
					//  lowest number of distinct interactions that any one article reported
					parent::addRDF(
						parent::triplifyString($iid,parent::getVoc()."minimum-number-interactions-reported",$id)
					);
				} else if($ns == "hpr") {
					//  higher number of distinct interactions that any one article reports
					parent::addRDF(
						parent::triplifyString($iid,parent::getVoc()."maximum-number-interactions-reported",$id)
					);
				} else if($ns = 'hp') {
					//  total number of unique PMIDs used to support the interaction 
					parent::addRDF(
						parent::triplifyString($iid,parent::getVoc()."number-supporting-articles",$id)
					);				
				}
			}

			// expansion method
			if($a[15]) {
				parent::addRDF(
					parent::triplifyString($iid,parent::getVoc()."expansion-method",$a[15])
				);
			}

			// host organism
			if($a[28] != '-') {
				$qname = $this->ParseString($a[28],$ns,$id,$label);
				parent::addRDF(
					parent::triplify($iid,parent::getVoc()."host-organism",$qname)
				);
			}

			// @todo add to record
			// created 2010/05/18
			$date = str_replace("/","-",$a[30])."T00:00:00Z";
			parent::addRDF(
				parent::triplifyString($iid,"dc:created", $date,"xsd:dateTime")
			);

			// taxon-sequence identical interaction group
			parent::addRDF(
				parent::triplify($iid,parent::getVoc()."taxon-sequence-identical-interaction-group", "irefindex_irigid:".$a[44])
			);

			// taxon-sequence similar interaction group
			parent::addRDF(
				parent::triplify($iid,parent::getVoc()."taxon-sequence-similar-interaction-group", "irefindex_crigid:".$a[50])
			);

			parent::writeRDFBufferToWriteFile();
		}
	}

	function ParseString($string,&$ns,&$id,&$label)
	{
		parent::getRegistry()->parseQName($string,$ns,$str);
		$this->Parse4IDLabel($str,$id,$label);
		$label = trim($label);
		$id = trim($id);
		if($ns == 'other' || $ns == 'xx') $ns = '';
		if($ns == 'complex') $ns = 'rogid';
		if($ns == 'hpr' || $ns == 'lpr' || $ns == 'hp' || $ns == 'np') return '';
		
		if($ns) {
			return "$ns:$id";
		} else return '';

	}

	function Parse4IDLabel($str,&$id,&$label)
	{
		$id='';$label='';
		preg_match("/(.*)\((.*)\)/",$str,$m);
		if(isset($m[1])) {
			$id = $m[1];
			$label = $m[2];
		} else {
			$id = $str;
		}
	}
	
}


?>
