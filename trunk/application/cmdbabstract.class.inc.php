<?php
require_once('../core/cmdbobject.class.inc.php');
require_once('../application/utils.inc.php');
require_once('../application/applicationcontext.class.inc.php');
require_once('../application/ui.linkswidget.class.inc.php');
////////////////////////////////////////////////////////////////////////////////////
/**
* Abstract class that implements some common and useful methods for displaying
* the objects
*/
////////////////////////////////////////////////////////////////////////////////////
abstract class cmdbAbstractObject extends CMDBObject
{
	
	public static function GetUIPage()
	{
		return './UI.php';
	}
	
	public static function ComputeUIPage($sClass)
	{
		static $aUIPagesCache = array(); // Cache to store the php page used to display each class of object
		if (!isset($aUIPagesCache[$sClass]))
		{
			$UIPage = false;
			if (is_callable("$sClass::GetUIPage"))
			{
				$UIPage = eval("return $sClass::GetUIPage();"); // May return false in case of error
			}
			$aUIPagesCache[$sClass] = $UIPage === false ? './UI.php' : $UIPage;
		}
		$sPage = $aUIPagesCache[$sClass];
		return $sPage;
	}

	protected static function MakeHyperLink($sObjClass, $sObjKey, $aAvailableFields)
	{
		$oAppContext = new ApplicationContext();	
		$sExtClassNameAtt = MetaModel::GetNameAttributeCode($sObjClass);
		$sPage = self::ComputeUIPage($sObjClass);
		// Use the "name" of the target class as the label of the hyperlink
		// unless it's not available in the external attributes...
		if (isset($aAvailableFields[$sExtClassNameAtt]))
		{
			$sLabel = $aAvailableFields[$sExtClassNameAtt];
		}
		else
		{
			$sLabel = implode(' / ', $aAvailableFields);
		}
		$sHint = htmlentities("$sObjClass::$sObjKey");
		return "<a href=\"$sPage?operation=details&class=$sObjClass&id=$sObjKey&".$oAppContext->GetForLink()."\" title=\"$sHint\">$sLabel</a>";
	}

	public function GetDisplayValue($sAttCode)
	{
		$sDisplayValue = "";
		$sStateAttCode = MetaModel::GetStateAttributeCode(get_class($this));
		if ($sStateAttCode == $sAttCode)
		{
			$aStates = MetaModel::EnumStates(get_class($this));
			$sDisplayValue = $aStates[$this->Get($sAttCode)]['label'];
		}
		else
		{
			$oAtt = MetaModel::GetAttributeDef(get_class($this), $sAttCode);
			
			if ($oAtt->IsExternalKey())
			{
				// retrieve the "external fields" linked to this external key
				$sTargetClass = $oAtt->GetTargetClass();
				$aAvailableFields = array();
				foreach (MetaModel::GetExternalFields(get_class($this), $sAttCode) as $oExtField)
				{
					$aAvailableFields[$oExtField->GetExtAttCode()] = $oExtField->GetAsHTML($this->Get($oExtField->GetCode()));
				}
				$sExtClassNameAtt = MetaModel::GetNameAttributeCode($sTargetClass);
				// Use the "name" of the target class as the label of the hyperlink
				// unless it's not available in the external fields...
				if (isset($aAvailableFields[$sExtClassNameAtt]))
				{
					$sDisplayValue = $aAvailableFields[$sExtClassNameAtt];
				}
				else
				{
					$sDisplayValue = implode(' / ', $aAvailableFields);
				}
			}
			else
			{
				$sDisplayValue = $this->GetAsHTML($sAttCode);
			}
		}
		return $sDisplayValue;
	}

	function DisplayBareDetails(web_page $oPage)
	{
		$oPage->add($this->GetBareDetails($oPage));		
	}

	function GetDisplayName()
	{
		return $this->GetAsHTML(MetaModel::GetNameAttributeCode(get_class($this)));
	}

	function GetBareDetails(web_page $oPage)
	{
		$sHtml = '';
		$oAppContext = new ApplicationContext();	
		$sStateAttCode = MetaModel::GetStateAttributeCode(get_class($this));
		$aDetails = array();
		$sClass = get_class($this);
		$aList = MetaModel::GetZListItems($sClass, 'details');

		foreach($aList as $sAttCode)
		{
			$iFlags = $this->GetAttributeFlags($sAttCode);
			if ( ($iFlags & OPT_ATT_HIDDEN) == 0)
			{
				// The field is visible in the current state of the object
				if ($sStateAttCode == $sAttCode)
				{
					// Special display for the 'state' attribute itself
					$sDisplayValue = $this->GetState();
				}
				else
				{
					$sDisplayValue = $this->GetAsHTML($sAttCode);
				}
				$aDetails[] = array('label' => MetaModel::GetLabel($sClass, $sAttCode), 'value' => $sDisplayValue);
			}
		}
		$sHtml .= $oPage->GetDetails($aDetails);
		return $sHtml;		
	}

	
	function DisplayDetails(web_page $oPage)
	{
		$sTemplate = Utils::ReadFromFile(MetaModel::GetDisplayTemplate(get_class($this)));
		if (!empty($sTemplate))
		{
			$oTemplate = new DisplayTemplate($sTemplate);
			$oTemplate->Render($oPage, array('class_name'=> MetaModel::GetName(get_class($this)),'class'=> get_class($this),'pkey'=> $this->GetKey(), 'name' => $this->GetName()));
		}
		else
		{
			// Standard Header with name, actions menu and history block
			$oPage->add("<div class=\"page_header\">\n");
			$oSingletonFilter = new DBObjectSearch(get_class($this));
			$oSingletonFilter->AddCondition('pkey', array($this->GetKey()));
			$oBlock = new MenuBlock($oSingletonFilter, 'popup', false);
			$oBlock->Display($oPage, -1);
			$oPage->add("<h1>".Metamodel::GetName(MetaModel::GetName(get_class($this))).": <span class=\"hilite\">".$this->GetDisplayName()."</span></h1>\n");
			$oHistoryFilter = new DBObjectSearch('CMDBChangeOpSetAttribute');
			$oHistoryFilter->AddCondition('objkey', $this->GetKey());
			$oBlock = new HistoryBlock($oHistoryFilter, 'toggle', false);
			$oBlock->Display($oPage, -1);
			$oPage->add("</div>\n");
			
			// Object's details
			// template not found display the object using the *old style*
			self::DisplayBareDetails($oPage);
			
			// Related objects
			$oPage->AddTabContainer('Related Objects');
			$oPage->SetCurrentTabContainer('Related Objects');
			foreach(MetaModel::ListAttributeDefs(get_class($this)) as $sAttCode=>$oAttDef)
			{
				if ((get_class($oAttDef) == 'AttributeLinkedSetIndirect') || (get_class($oAttDef) == 'AttributeLinkedSet'))
				{
					$oPage->SetCurrentTab($oAttDef->GetLabel());
					$oPage->p($oAttDef->GetDescription());
					
					if (get_class($oAttDef) == 'AttributeLinkedSet')
					{
						$sTargetClass = $oAttDef->GetLinkedClass();
						$oFilter = new DBObjectSearch($sTargetClass);
						$oFilter->AddCondition($oAttDef->GetExtKeyToMe(), $this->GetKey()); // @@@ condition has same name as field ??

						$oBlock = new DisplayBlock($oFilter, 'list', false);
						$oBlock->Display($oPage, 0);
					}
					else // get_class($oAttDef) == 'AttributeLinkedSetIndirect'
					{
						$sLinkClass = $oAttDef->GetLinkedClass();
						// Transform the DBObjectSet into a CMBDObjectSet !!!
						$aLinkedObjects = $this->Get($sAttCode)->ToArray(false);
						if (count($aLinkedObjects) > 0)
						{
							$oSet = CMDBObjectSet::FromArray($sLinkClass, $aLinkedObjects);
							$this->DisplaySet($oPage, $oSet, $oAttDef->GetExtKeyToMe());
						}
					}					
				}
			}
			$oPage->SetCurrentTab('');
		}
	}
	
	function DisplayPreview(web_page $oPage)
	{
		$aDetails = array();
		$sClass = get_class($this);
		$aList = MetaModel::GetZListItems($sClass, 'preview');
		foreach($aList as $sAttCode)
		{
			$aDetails[] = array('label' => MetaModel::GetLabel($sClass, $sAttCode), 'value' =>$this->GetAsHTML($sAttCode));
		}
		$oPage->details($aDetails);		
	}
	
	// Comment by Rom: this helper may be used to display objects of class DBObject
	//                 -> I am using this to display the changes history 
	public static function DisplaySet(web_page $oPage, CMDBObjectSet $oSet, $sLinkageAttribute = '')
	{
		$oPage->add(self::GetDisplaySet($oPage, $oSet, $sLinkageAttribute));
	}
	
	public static function GetDisplaySet(web_page $oPage, CMDBObjectSet $oSet, $sLinkageAttribute = '', $bDisplayMenu = true)
	{
		$sHtml = '';
		$oAppContext = new ApplicationContext();
		$sClassName = $oSet->GetFilter()->GetClass();
		$aAttribs = array();
		$aList = MetaModel::GetZListItems($sClassName, 'list');
		if (!empty($sLinkageAttribute))
		{
			// The set to display is in fact a set of links between the object specified in the $sLinkageAttribute
			// and other objects...
			// The display will then group all the attributes related to the link itself:
			// | Link_attr1 | link_attr2 | ... || Object_attr1 | Object_attr2 | Object_attr3 | .. | Object_attr_n |
			$aAttDefs = MetaModel::ListAttributeDefs($sClassName);
			assert(isset($aAttDefs[$sLinkageAttribute]));
			$oAttDef = $aAttDefs[$sLinkageAttribute];
			assert($oAttDef->IsExternalKey());
			// First display all the attributes specific to the link record
			foreach($aList as $sLinkAttCode)
			{
				$oLinkAttDef = $aAttDefs[$sLinkAttCode];
				if ( (!$oLinkAttDef->IsExternalKey()) && (!$oLinkAttDef->IsExternalField()) )
				{
					$aDisplayList[] = $sLinkAttCode;
				}
			}
			// Then display all the attributes neither specific to the link record nor to the 'linkage' object (because the latter are constant)
			foreach($aList as $sLinkAttCode)
			{
				$oLinkAttDef = $aAttDefs[$sLinkAttCode];
				if (($oLinkAttDef->IsExternalKey() && ($sLinkAttCode != $sLinkageAttribute))
					|| ($oLinkAttDef->IsExternalField() && ($oLinkAttDef->GetKeyAttCode()!=$sLinkageAttribute)) )
				{
					$aDisplayList[] = $sLinkAttCode;
				}
			}
			// First display all the attributes specific to the link
			// Then display all the attributes linked to the other end of the relationship
			$aList = $aDisplayList;
		}
		foreach($aList as $sAttCode)
		{
			$aAttribs['key'] = array('label' => '', 'description' => 'Click to display');
			$aAttribs[$sAttCode] = array('label' => MetaModel::GetLabel($sClassName, $sAttCode), 'description' => MetaModel::GetDescription($sClassName, $sAttCode));
		}
		$aValues = array();
		$oSet->Seek(0);
		while ($oObj = $oSet->Fetch())
		{
			$aRow['key'] = $oObj->GetKey();
			foreach($aList as $sAttCode)
			{
				$aRow[$sAttCode] = $oObj->GetAsHTML($sAttCode);
			}
			$aValues[] = $aRow;
		}
		$oMenuBlock = new MenuBlock($oSet->GetFilter());
		$sHtml .= '<table class="listContainer">';
		$sColspan = '';
		if ($bDisplayMenu)
		{
			$sColspan = 'colspan="2"';
			$sHtml .= '<tr class="containerHeader"><td>&nbsp;'.$oSet->Count().' object(s)</td><td>';
			$sHtml .= $oMenuBlock->GetRenderContent($oPage, $sLinkageAttribute);
			$sHtml .= '</td></tr>';
		}
		$sHtml .= "<tr><td $sColspan>";
		$sHtml .= $oPage->GetTable($aAttribs, $aValues, array('class'=>$sClassName, 'filter'=>$oSet->GetFilter()->serialize(), 'preview' => true));
		$sHtml .= '</td></tr>';
		$sHtml .= '</table>';
		return $sHtml;
	}
	
	static function DisplaySetAsCSV(web_page $oPage, CMDBObjectSet $oSet, $aParams = array())
	{
		$oPage->add(self::GetSetAsCSV($oSet, $aParams));
	}
	
	static function GetSetAsCSV(DBObjectSet $oSet, $aParams = array())
	{
		$sSeparator = isset($aParams['separator']) ? $aParams['separator'] : ','; // default separator is comma
		$sTextQualifier = isset($aParams['text_qualifier']) ? $aParams['text_qualifier'] : '"'; // default text qualifier is double quote

		$oAppContext = new ApplicationContext();
		$sClassName = $oSet->GetFilter()->GetClass();
		$aAttribs = array();
		$aList = MetaModel::GetZListItems($sClassName, 'details');
		$aHeader = array();
		$aHeader[] = MetaModel::GetKeyLabel($sClassName);
		foreach($aList as $sAttCode)
		{
			$aHeader[] = MetaModel::GetLabel($sClassName, $sAttCode);
		}
		$sHtml = '#'.$oSet->GetFilter()->ToOQL()."\n";
		$sHtml .= implode($sSeparator, $aHeader)."\n";
		$oSet->Seek(0);
		while ($oObj = $oSet->Fetch())
		{
			$aRow = array();
			$aRow[] = $oObj->GetKey();
			foreach($aList as $sAttCode)
			{
				if (strstr($oObj->Get($sAttCode), $sSeparator)) // Escape the text only when it contains the separator
				{
					$aRow[] = $sTextQualifier.$oObj->Get($sAttCode).$sTextQualifier;
				}
				else
				{
					$aRow[] = $oObj->Get($sAttCode);
				}
			}
			$sHtml .= implode($sSeparator, $aRow)."\n";
		}
		
		return $sHtml;
	}
	
	static function DisplaySetAsXML(web_page $oPage, CMDBObjectSet $oSet, $aParams = array())
	{
		$oAppContext = new ApplicationContext();
		$sClassName = $oSet->GetFilter()->GetClass();
		$aAttribs = array();
		$aList = MetaModel::GetZListItems($sClassName, 'details');
		$oPage->add("<Set>\n");
		$oSet->Seek(0);
		while ($oObj = $oSet->Fetch())
		{
			$oPage->add("<$sClassName id=\"".$oObj->GetKey()."\">\n");
			foreach(MetaModel::ListAttributeDefs($sClassName) as $sAttCode=>$oAttDef)
			{
				if (($oAttDef->IsWritable()) && ($oAttDef->IsScalar()) && ($sAttCode != 'finalclass') )
				{
					$sValue = $oObj->GetAsXML($sAttCode);
					$oPage->add("<$sAttCode>$sValue</$sAttCode>\n");
				}
			}
			$oPage->add("</$sClassName>\n");
		}
		$oPage->add("</Set>\n");
	}

	// By rom
	function DisplayChangesLog(web_page $oPage)
	{
		$oFltChangeOps = new CMDBSearchFilter('CMDBChangeOpSetAttribute');
		$oFltChangeOps->AddCondition('objkey', $this->GetKey(), '=');
		$oFltChangeOps->AddCondition('objclass', get_class($this), '=');
		$oSet = new CMDBObjectSet($oFltChangeOps, array('date' => false)); // order by date descending (i.e. false)
		$count = $oSet->Count();
		if ($count > 0)
		{
			$oPage->p("Changes log ($count):");
			self::DisplaySet($oPage, $oSet);
		}
		else
		{
			$oPage->p("Changes log is empty");
		}
	}
	
	public static function DisplaySearchForm(web_page $oPage, CMDBObjectSet $oSet, $aExtraParams = array())
	{

		$oPage->add(self::GetSearchForm($oPage, $oSet, $aExtraParams));
	}
	
	public static function GetSearchForm(web_page $oPage, CMDBObjectSet $oSet, $aExtraParams = array())
	{
		$sHtml = '';
		$numCols=4;
		$sClassName = $oSet->GetFilter()->GetClass();
		$oUnlimitedFilter = new DBObjectSearch($sClassName);
		$sHtml .= "<form>\n";
		$index = 0;
		$sHtml .= "<table>\n";
		$aFilterCriteria = $oSet->GetFilter()->GetCriteria();
		$aMapCriteria = array();
		foreach($aFilterCriteria as $aCriteria)
		{
			$aMapCriteria[$aCriteria['filtercode']][] = array('value' => $aCriteria['value'], 'opcode' => $aCriteria['opcode']);
		}
		$aList = MetaModel::GetZListItems($sClassName, 'standard_search');
		foreach($aList as $sFilterCode)
		{
			if (($index % $numCols) == 0)
			{
				if ($index != 0)
				{
					$sHtml .= "</tr>\n";
				}
				$sHtml .= "<tr>\n";
			}
			$sFilterValue = '';
			$sFilterValue = utils::ReadParam($sFilterCode, '');
			$sFilterOpCode = null; // Use the default 'loose' OpCode
			if (empty($sFilterValue))
			{
				if (isset($aMapCriteria[$sFilterCode]))
				{
					if (count($aMapCriteria[$sFilterCode]) > 1)
					{
						$sFilterValue = '* mixed *';
					}
					else
					{
						$sFilterValue = $aMapCriteria[$sFilterCode][0]['value'];
						$sFilterOpCode = $aMapCriteria[$sFilterCode][0]['opcode'];
					}
					if ($sFilterCode != 'company')
					{
						$oUnlimitedFilter->AddCondition($sFilterCode, $sFilterValue, $sFilterOpCode);
					}
				}
			}
            $aAllowedValues = MetaModel::GetAllowedValues_flt($sClassName, $sFilterCode, array(), '');
            if ($aAllowedValues != null)
            {
                //Enum field or external key, display a combo
            	$sValue = "<select name=\"$sFilterCode\">\n";
            	$sValue .= "<option value=\"\">* Any *</option>\n";
            	foreach($aAllowedValues as $key => $value)
            	{
            		if ($sFilterValue == $key)
            		{
            			$sSelected = ' selected';
            		}
            		else
            		{
            			$sSelected = '';
            		}
            		$sValue .= "<option value=\"$key\"$sSelected>$value</option>\n";
            	}
            	$sValue .= "</select>\n";
		        $sHtml .= "<td><label>".MetaModel::GetFilterLabel($sClassName, $sFilterCode).":</label></td><td>$sValue</td>\n";
            }
            else
            {
                // Any value is possible, display an input box
		        $sHtml .= "<td><label>".MetaModel::GetFilterLabel($sClassName, $sFilterCode).":</label></td><td><input class=\"textSearch\" name=\"$sFilterCode\" value=\"$sFilterValue\"/></td>\n";
            }
			$index++;
		}
		if (($index % $numCols) != 0)
		{
			$sHtml .= "<td colspan=\"".(2*($numCols - ($index % $numCols)))."\"></td>\n";
		}
		$sHtml .= "</tr>\n";
		$sHtml .= "<tr><td colspan=\"".(2*$numCols)."\" align=\"right\"><input type=\"submit\" value=\" Search \"></td></tr>\n";
		$sHtml .= "</table>\n";
		foreach($aExtraParams as $sName => $sValue)
		{
			$sHtml .= "<input type=\"hidden\" name=\"$sName\" value=\"$sValue\">\n";
		}
		$sHtml .= "<input type=\"hidden\" name=\"dosearch\" value=\"1\">\n";
		$sHtml .= "</form>\n";
		// Soem Debug dumps...
		//$sHtml .= "<tt>".$oSet->GetFilter()->__DescribeHTML()."</tt><br/>\n";
		//$sHtml .= "<tt>encoding=\"text/serialize\" : ".$oSet->GetFilter()->serialize()."</tt><br/>\n";
		//$sHtml .= "<tt>encoding=\"text/sibusql\" : ".$oSet->GetFilter()->ToSibusQL()."</tt><br/>\n";
		//$sHtml .= "<tt>(Unlimited) ".$oUnlimitedFilter->__DescribeHTML()."</tt><br/>\n";
		//$sHtml .= "<tt>encoding=\"text/serialize\" : ".$oUnlimitedFilter->serialize()."</tt><br/>\n";
		//$sHtml .= "<tt>encoding=\"text/sibusql\" : ".$oUnlimitedFilter->ToSibusQL()."</tt>\n";
		return $sHtml;
	}
	
	public static function GetFormElementForField($oPage, $sClass, $sAttCode, $oAttDef, $value = '', $sDisplayValue = '', $iId = '')
	{
		static $iInputId = 0;
		if (!empty($iId))
		{
			$iInputId = $iId;
		}
		else
		{
			$iInputId++;
		}
		if (!$oAttDef->IsExternalField())
		{
			switch($oAttDef->GetEditClass())
			{
				case 'Date':
				$sHTMLValue = "<input type=\"text\" size=\"20\" name=\"attr_$sAttCode\" value=\"$value\" id=\"$iInputId\" class=\"date-pick\"/>";
				break;
				
				case 'Text':
					$sHTMLValue = "<textarea name=\"attr_$sAttCode\" rows=\"8\" cols=\"40\" id=\"$iInputId\">$value</textarea>";
				break;
	
				case 'List':
					$oWidget = new UILinksWidget($sClass, $sAttCode, $iInputId);
					$sHTMLValue = $oWidget->Display($oPage, $value);
				break;
							
				case 'String':
				default:
			    $aAllowedValues = MetaModel::GetAllowedValues_att($sClass, $sAttCode, array(), '');
				if ($aAllowedValues !== null)
				{
					//Enum field or external key, display a combo
					if (count($aAllowedValues) == 0)
					{
						$sHTMLValue = "<input type=\"text\" size=\"70\" value=\"\" name=\"attr_$sAttCode\"  id=\"$iInputId\"/>";
					}
					else if (count($aAllowedValues) > 50)
					{
						// too many choices, use an autocomplete
						// The input for the auto complete
						$sHTMLValue = "<input type=\"text\" id=\"label_$iInputId\" size=\"50\" name=\"\" value=\"$sDisplayValue\" />";
						// another hidden input to store & pass the object's Id
						$sHTMLValue .= "<input type=\"hidden\" id=\"$iInputId\" name=\"attr_$sAttCode\" value=\"$value\" />\n";
						$oPage->add_ready_script("\$('#label_$iInputId').autocomplete('./ajax.render.php', { minChars:3, onItemSelect:selectItem, onFindValue:findValue, formatItem:formatItem, autoFill:true, keyHolder:'#$iInputId', extraParams:{operation:'autocomplete', sclass:'$sClass',attCode:'".$sAttCode."'}});");
					}
					else
					{
						// Few choices, use a normal 'select'
						$sHTMLValue = "<select name=\"attr_$sAttCode\"  id=\"$iInputId\">\n";
						foreach($aAllowedValues as $key => $display_value)
						{
							$sSelected = ($value == $key) ? ' selected' : '';
							$sHTMLValue .= "<option value=\"$key\"$sSelected>$display_value</option>\n";
						}
						$sHTMLValue .= "</select>\n";
					}
				}
				else
				{
					$sHTMLValue = "<input type=\"text\" size=\"50\" name=\"attr_$sAttCode\" value=\"$value\" id=\"$iInputId\">";
				}
			}
		}
		return $sHTMLValue;
	}
	
	public function DisplayModifyForm(web_page $oPage)
	{
		$oAppContext = new ApplicationContext();
		$sStateAttCode = MetaModel::GetStateAttributeCode(get_class($this));
		$iKey = $this->GetKey();
		$aDetails = array();
		$oPage->add("<form method=\"post\">\n");
		foreach(MetaModel::ListAttributeDefs(get_class($this)) as $sAttCode=>$oAttDef)
		{
			if ('finalclass' == $sAttCode) // finalclass is a reserved word, hardcoded !
			{
				// Do nothing, the class field is always hidden, it cannot be edited
			}
			else if ($sStateAttCode == $sAttCode)
			{
				// State attribute is always read-only from the UI
				$sHTMLValue = $this->GetState();
				$aDetails[] = array('label' => $oAttDef->GetLabel(), 'value' => $sHTMLValue);
			}
			else if (!$oAttDef->IsExternalField())
			{
				$iFlags = $this->GetAttributeFlags($sAttCode);				
				if ($iFlags & OPT_ATT_HIDDEN)
				{
					// Attribute is hidden, do nothing
				}
				else
				{
					if ($iFlags & OPT_ATT_READONLY)
					{
						// Attribute is read-only
						$sHTMLValue = $this->GetAsHTML($sAttCode);
					}
					else
					{
						$sValue = $this->Get($sAttCode);
						$sDisplayValue = $this->GetDisplayValue($sAttCode);
						$sHTMLValue = self::GetFormElementForField($oPage, get_class($this), $sAttCode, $oAttDef, $sValue, $sDisplayValue);
					}
					$aDetails[] = array('label' => $oAttDef->GetLabel(), 'value' => $sHTMLValue);
				}
			}
		}
		$oPage->details($aDetails);
		$oPage->add("<input type=\"hidden\" name=\"id\" value=\"$iKey\">\n");
		$oPage->add("<input type=\"hidden\" name=\"class\" value=\"".get_class($this)."\">\n");
		$oPage->add("<input type=\"hidden\" name=\"operation\" value=\"apply_modify\">\n");
		$oPage->add("<input type=\"hidden\" name=\"transaction_id\" value=\"".utils::GetNewTransactionId()."\">\n");
		$oPage->add($oAppContext->GetForForm());
		$oPage->add("<button type=\"button\" class=\"action\" onClick=\"goBack()\"><span>Cancel</span></button>&nbsp;&nbsp;&nbsp;&nbsp;\n");
		$oPage->add("<button type=\"submit\" class=\"action\"><span>Apply</span></button>\n");
		$oPage->add("</form>\n");
	}
	
	public static function DisplayCreationForm(web_page $oPage, $sClass, $oObjectToClone = null)
	{
		$oAppContext = new ApplicationContext();
		$aDetails = array();
		$sOperation = ($oObjectToClone == null) ? 'apply_new' : 'apply_clone';
		$sStateAttCode = MetaModel::GetStateAttributeCode(get_class($oObjectToClone));
		$oPage->add("<form method=\"post\">\n");
		foreach(MetaModel::ListAttributeDefs($sClass) as $sAttCode=>$oAttDef)
		{
			if ('finalclass' == $sAttCode) // finalclass is a reserved word, hardcoded !
			{
				// Do nothing, the class field is always hidden, it cannot be edited
			}
			else if ($sStateAttCode == $sAttCode)
			{
				// State attribute is always read-only from the UI
				$sHTMLValue = $oObjectToClone->GetState();
				$aDetails[] = array('label' => $oAttDef->GetLabel(), 'value' => $sHTMLValue);
			}
			else if (!$oAttDef->IsExternalField())
			{
				$sValue = ($oObjectToClone == null) ? '' : $oObjectToClone->Get($sAttCode);
				$sDisplayValue = ($oObjectToClone == null) ? '' : $oObjectToClone->GetDisplayValue($sAttCode);
				$sHTMLValue = self::GetFormElementForField($oPage, $sClass, $sAttCode, $oAttDef, $sValue, $sDisplayValue);
				$aDetails[] = array('label' => $oAttDef->GetLabel(), 'value' => $sHTMLValue);
			}
		}
		$oPage->details($aDetails);
		if ($oObjectToClone != null)
		{
			$oPage->add("<input type=\"hidden\" name=\"clone_id\" value=\"".$oObjectToClone->GetKey()."\">\n");
		}
		$oPage->add("<input type=\"hidden\" name=\"class\" value=\"$sClass\">\n");
		$oPage->add("<input type=\"hidden\" name=\"operation\" value=\"$sOperation\">\n");
		$oPage->add("<input type=\"hidden\" name=\"transaction_id\" value=\"".utils::GetNewTransactionId()."\">\n");
		$oPage->add($oAppContext->GetForForm());
		$oPage->add("<button type=\"button\" class=\"action\" onClick=\"goBack()\"><span>Cancel</span></button>&nbsp;&nbsp;&nbsp;&nbsp;\n");
		$oPage->add("<button type=\"submit\" class=\"action\"><span>Apply</span></button>\n");
		$oPage->add("</form>\n");
	}
}
?>
