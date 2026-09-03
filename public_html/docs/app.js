// Generated — theme toggle, copy buttons, client-side search.
(function(){
  var root=document.documentElement;
  var btn=document.getElementById('theme');
  if(btn)btn.addEventListener('click',function(){
    var cur=root.getAttribute('data-theme')==='light'?'dark':'light';
    root.setAttribute('data-theme',cur);
    try{localStorage.setItem('spi-theme',cur);}catch(e){}
  });
  document.querySelectorAll('.copy').forEach(function(b){
    b.addEventListener('click',function(){
      var pre=b.closest('.code').querySelector('code');
      navigator.clipboard.writeText(pre.textContent).then(function(){b.textContent='Copied!';setTimeout(function(){b.textContent='Copy';},1400);}).catch(function(){});
    });
  });
  var q=document.getElementById('q'),box=document.getElementById('results'),idx=null,sel=-1,items=[];
  function load(){if(idx)return Promise.resolve(idx);return fetch('/docs/search-index.json').then(function(r){return r.json();}).then(function(d){idx=d;return d;});}
  function render(list){
    items=list;sel=-1;
    if(!list.length){box.innerHTML='<div class="r-empty">No matches.</div>';box.hidden=false;return;}
    box.innerHTML=list.map(function(d){return '<a href="'+d.url+'"><div class="r-title">'+d.title+'</div><div class="r-sum">'+d.summary+'</div></a>';}).join('');
    box.hidden=false;
  }
  function search(term){
    term=term.trim().toLowerCase();if(!term){box.hidden=true;return;}
    load().then(function(d){render(d.filter(function(x){return x.text.indexOf(term)>-1;}).slice(0,12));});
  }
  if(q){
    q.addEventListener('input',function(){search(q.value);});
    q.addEventListener('keydown',function(e){
      var links=box.querySelectorAll('a');
      if(e.key==='ArrowDown'){e.preventDefault();sel=Math.min(sel+1,links.length-1);}
      else if(e.key==='ArrowUp'){e.preventDefault();sel=Math.max(sel-1,0);}
      else if(e.key==='Enter'&&links[sel]){location.href=links[sel].getAttribute('href');return;}
      else if(e.key==='Escape'){box.hidden=true;return;}
      links.forEach(function(l,i){l.classList.toggle('sel',i===sel);});
    });
    document.addEventListener('click',function(e){if(!e.target.closest('.search'))box.hidden=true;});
  }
})();
