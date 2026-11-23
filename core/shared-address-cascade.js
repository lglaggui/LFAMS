/* FAMS 3.4 shared Province -> Municipality/City -> Barangay cascade. */
window.FAMSInitAddressCascade = function(opts){
    opts=opts||{};
    const province=document.getElementById(opts.provinceId||'province');
    const municipality=document.getElementById(opts.municipalityId||'municipality');
    const barangay=document.getElementById(opts.barangayId||'barangay');
    if(!province||!municipality||!barangay) return;
    const old={province:String(opts.province||''),municipality:String(opts.municipality||''),barangay:String(opts.barangay||'')};
    const api=opts.api||'address_api.php';
    const getJson=async(params)=>{const r=await fetch(api+'?'+params,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});const d=await r.json().catch(()=>({}));if(!r.ok||d.ok===false)throw new Error(d.error||'Address request failed');return d;};
    const remoteItems=async(urls,preferredKey)=>{for(const url of urls){try{const r=await fetch(url,{mode:'cors',cache:'no-store',headers:{Accept:'application/json'}});if(!r.ok)continue;const d=await r.json();let items=[];if(preferredKey&&Array.isArray(d?.[preferredKey]))items=d[preferredKey];else if(Array.isArray(d?.data))items=d.data;else if(Array.isArray(d?.result))items=d.result;else if(Array.isArray(d?.results))items=d.results;else if(Array.isArray(d))items=d;items=items.map(x=>{const p=(x&&typeof x.properties==='object'&&x.properties)?x.properties:x;return {code:String(p?.psgc_code||p?.psgc10DigitCode||p?.code||''),name:String(p?.name||'')}}).filter(x=>x.code&&x.name);if(items.length)return items;}catch(e){/* continue */}}return [];};
    const province9=code=>String(code||'').length===10?String(code).slice(0,9):String(code||'');
    const province4=code=>{const c=String(code||'');return c.length===10?c.slice(0,2)+c.slice(2,4):c.slice(0,4)};
    const city9=code=>String(code||'').length===10?String(code).slice(0,9):String(code||'');
    const city6=code=>String(code||'').slice(0,6);
    const loadRemoteBarangays=async code=>remoteItems([
        'https://psgc.cloud/api/v2/cities-municipalities/'+encodeURIComponent(code)+'/barangays',
        'https://philippine-datasets-api.nowcraft.ing/api/cities/'+encodeURIComponent(code),
        'https://philippine-datasets-api.nowcraft.ing/api/cities/'+encodeURIComponent(city9(code)),
        'https://psgc.gitlab.io/api/cities/'+encodeURIComponent(city9(code))+'/barangays/',
        'https://psgc.cloud/api/v1/cities-municipalities/'+encodeURIComponent(city6(code))+'/barangays'
    ],'barangays');
    const loadRemoteMunicipalities=async code=>remoteItems([
        'https://psgc.cloud/api/v2/provinces/'+encodeURIComponent(code)+'/cities-municipalities',
        'https://philippine-datasets-api.nowcraft.ing/api/provinces/'+encodeURIComponent(code),
        'https://philippine-datasets-api.nowcraft.ing/api/provinces/'+encodeURIComponent(province9(code)),
        'https://psgc.gitlab.io/api/provinces/'+encodeURIComponent(province9(code))+'/cities-municipalities/',
        'https://psgc.cloud/api/v1/provinces/'+encodeURIComponent(province4(code))+'/cities-municipalities'
    ],'cities');
    const setOptions=(el,items,placeholder)=>{el.innerHTML='';const ph=document.createElement('option');ph.value='';ph.textContent=placeholder;el.appendChild(ph);(items||[]).forEach(x=>{const name=String(x.name||x.code||'');const o=document.createElement('option');o.value=name;o.textContent=name;o.dataset.code=String(x.code||'');el.appendChild(o);});};
    const findOption=(el,value)=>{const t=String(value||'').trim().toUpperCase();return [...el.options].find(o=>String(o.value).trim().toUpperCase()===t||String(o.textContent).trim().toUpperCase()===t);};
    async function loadBarangays(code,selected){
        barangay.disabled=true;setOptions(barangay,[],'Loading barangays…');
        let items=[];
        try{const d=await getJson('action=barangays&municipality='+encodeURIComponent(code));items=Array.isArray(d.items)?d.items:[]}catch(e){/* remote fallback below */}
        if(!items.length && String(code).startsWith('010')) items=await loadRemoteBarangays(code);
        setOptions(barangay,items,'Select barangay');barangay.disabled=false;
        if(selected){const o=findOption(barangay,selected);if(o)barangay.value=o.value;}
        if(!items.length)setOptions(barangay,[],'No barangays available — refresh local PSGC data');
    }
    async function loadMunicipalities(code,selected,selectedBarangay){
        municipality.disabled=true;barangay.disabled=true;setOptions(municipality,[],'Loading municipalities / cities…');setOptions(barangay,[],'Select municipality / city first');
        let items=[];
        try{const d=await getJson('action=municipalities&province='+encodeURIComponent(code));items=Array.isArray(d.items)?d.items:[]}catch(e){/* remote fallback below */}
        if(!items.length && String(code).startsWith('010')) items=await loadRemoteMunicipalities(code);
        setOptions(municipality,items,'Select municipality / city');municipality.disabled=false;
        if(selected){const o=findOption(municipality,selected);if(o){municipality.value=o.value;await loadBarangays(o.dataset.code||o.value,selectedBarangay);}}
        if(!items.length)setOptions(municipality,[],'No municipalities / cities available — refresh local PSGC data');
    }
    async function loadProvinces(){
        try{const d=await getJson('action=provinces');setOptions(province,Array.isArray(d.items)?d.items:[],'Select province');province.disabled=false;if(old.province){const o=findOption(province,old.province);if(o){province.value=o.value;await loadMunicipalities(o.dataset.code||o.value,old.municipality,old.barangay);}}}
        catch(e){setOptions(province,[],'Address list unavailable — contact admin');province.disabled=false;}
    }
    province.addEventListener('change',()=>{const o=province.selectedOptions[0];setOptions(municipality,[],'Select province first');setOptions(barangay,[],'Select municipality / city first');if(o&&o.dataset.code)loadMunicipalities(o.dataset.code);});
    municipality.addEventListener('change',()=>{const o=municipality.selectedOptions[0];setOptions(barangay,[],'Select municipality / city first');if(o&&o.dataset.code)loadBarangays(o.dataset.code);});
    loadProvinces();
};
