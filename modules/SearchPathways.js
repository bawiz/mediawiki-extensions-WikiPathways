var SearchPathways = {};

SearchPathways.resultId = "searchResults";
SearchPathways.loadId = "loading";
SearchPathways.errorId = "error";
SearchPathways.moreId = "more";

SearchPathways.currentSearchId = null;
SearchPathways.currentIndex = 0;
SearchPathways.currentResults = [];
SearchPathways.batchSize = 12;
SearchPathways.untilMore = 2;

SearchPathways.doSearch = function() {
	SearchPathways.clearResults();
	SearchPathways.resetIndex();
	SearchPathways.showProgress();

	var form = document.getElementById('searchForm');
	var div = document.getElementById(SearchPathways.resultId);
	var query = form.elements['query'].value;
	var species = form.elements['species'].value;
	var ids = form.elements['ids'].value;
	var codes = form.elements['codes'].value;
	var type = form.elements['type'].value;

	$.ajax(
		mw.util.wikiScript() + '?' + $.param( {
			action: 'ajax',
			rs: "WikiPathways\\SearchPathwaysAjax::doSearch",
			rsargs: [query, species, ids, codes, type]
		} ), {
			complete: SearchPathways.processResults,
			dataType: "xml"
		} );
};

SearchPathways.resetIndex = function() {
	SearchPathways.currentSearchId = new Date().getTime();
	SearchPathways.currentIndex = 0;
	SearchPathways.currentResults = [];
};

SearchPathways.processResults = function(xhr) {
	if(SearchPathways.checkResponse(xhr)) {
		var xml = SearchPathways.getRequestXML(xhr);
		var nodes = xml.getElementsByTagName("pathway");

		//Collect the results
		for(var i=0;i<nodes.length;i++) {
			var n = nodes[i];
			var pw = n.firstChild.nodeValue;
			SearchPathways.currentResults.push(pw);
		}

		var div = document.getElementById(SearchPathways.resultId);
		if(nodes.length == 0) {
			div.innerHTML = "<b>No Results</b>";
			SearchPathways.hideProgress();
		} else {
			div.innerHTML = "<div class='resultCounter'><b>" + nodes.length + " pathways found</b></div>";
			//Now load the results in batches
			SearchPathways.loadBatch();
		}
	}
};

SearchPathways.loadBatch = function() {
	var index = SearchPathways.currentIndex;
	var results = SearchPathways.currentResults;
	var size = SearchPathways.batchSize;

	if(index >= results.length) {
		SearchPathways.hideProgress();
		return;
	}

	var end = Math.min(results.length, index + size);
	var batch = [];

	for(var i=index;i<end;i++) {
		batch.push(results[i]);
	}

	SearchPathways.currentIndex = end;
	$.ajax(
		mw.util.wikiScript() + '?' + $.param( {
			action: 'ajax',
			rs: "WikiPathways\\SearchPathwaysAjax::getResults",
			rsargs: [batch, SearchPathways.currentSearchId]
		} ), {
			complete: SearchPathways.processBatch,
			dataType: "xml"
		} );
};

SearchPathways.more = function() {
	var div = document.getElementById(SearchPathways.moreId);
	div.innerHTML = "";

	SearchPathways.showProgress();

	SearchPathways.loadBatch();
};

SearchPathways.all = function() {
	var div = document.getElementById(SearchPathways.moreId);
	div.innerHTML = "";

	SearchPathways.untilMore = -1;

	SearchPathways.showProgress();

	SearchPathways.loadBatch();
};

SearchPathways.processBatch = function(xhr) {
	if(SearchPathways.checkResponse(xhr)) {
		var xml = SearchPathways.getRequestXML(xhr);
		var htmlNode = xml.getElementsByTagName("htmlcontent")[0];
		var sid = xml.getElementsByTagName("searchid")[0];
		sid = sid.firstChild.nodeValue;

		if(sid == SearchPathways.currentSearchId) {
			var div = document.getElementById(SearchPathways.resultId);
			div.innerHTML += SearchPathways.getNodeText(htmlNode);

			if(SearchPathways.untilMore > 0 && SearchPathways.currentIndex >= SearchPathways.batchSize * SearchPathways.untilMore) {
			SearchPathways.hideProgress();
			div = document.getElementById(SearchPathways.moreId);
			div.innerHTML = "";

			if(SearchPathways.currentIndex < SearchPathways.currentResults.length) {
				var more = document.createElement("a");
				more.href = "javascript:SearchPathways.all();";
				more.innerHTML = "Show all results";
				div.appendChild(more);
				return;
			}
		}

			SearchPathways.loadBatch();
		}
	}
};

//Workaround for size limit of nodeValue in FF
//From: http://stackoverflow.com/questions/4411229/size-limit-to-javascript-node-nodevalue-field
SearchPathways.getNodeText = function(xmlNode) {
	if(!xmlNode) return '';
	if(typeof(xmlNode.textContent) != "undefined") return xmlNode.textContent;
	return xmlNode.firstChild.nodeValue;
};

SearchPathways.clearResults = function() {
	var div = document.getElementById(SearchPathways.resultId);
	div.innerHTML = "";
};

SearchPathways.showProgress = function() {
	var div = document.getElementById(SearchPathways.loadId);
	if ( div ) {
		div.style.display = "block";
	}
};

SearchPathways.hideProgress = function() {
	var div = document.getElementById(SearchPathways.loadId);
	if ( div ) {
		div.style.display = "none";
	}
};

SearchPathways.checkResponse = function(xhr) {
	if (xhr.readyState==4){
		if (xhr.status!=200) {
			SearchPathways.showError("Error: unable to process search.", xhr.responseText);
		}
	} else {
		SearchPathways.showError("Error: unable to process search.", xhr.responseText);
	}
};

SearchPathways.showError = function(e, details) {
	SearchPathways.hideProgress();
	var div = document.getElementById(SearchPathways.errorId);
	var html = "<B>" + e + "</B>";
	if(details) {
	 html += "<PRE>" + details + "</PRE>";
	}
	if ( div ) {
		div.innerHTML = html;
	}
};

SearchPathways.getRequestXML = function(xhr) {
	var text = xhr.responseText.replace(/^\s+|\s+$/g, '');
	return SearchPathways.parseXML(text);
};

SearchPathways.parseXML = function(xml) {
	var xmlDoc = null;
	if (window.DOMParser) {
		var parser = new DOMParser();
		xmlDoc = parser.parseFromString(xml, "text/xml");
	} else { //Internet Explorer
		xmlDoc = new ActiveXObject("Microsoft.XMLDOM");
		xmlDoc.async = "false";
		xmlDoc.loadXML(xml);
	}
	return xmlDoc;
};

$("#searchResults").ready( function() {SearchPathways.doSearch();} );
