<?php
// Loaded by run.php after contracts.php. Slot fit (TCH ADR 0039) in the write check: a slot that carries a `fit` is
// held to its kind, shape, size, ground and words; a slot without one is checked exactly as before. Fictional files,
// built byte by byte so the checks run without GD; the corner reading runs too when GD is present.

$fitRoot=sys_get_temp_dir().'/cowork-slot-fit-'.bin2hex(random_bytes(6));
mkdir($fitRoot.'/images/demo',0777,true);
// A PNG of a size and colour type (2 RGB, 6 RGBA, 3 palette), optionally with a tRNS chunk: its header only.
$fitPng=function(int $w,int $h,int $colour=2,bool $trns=false):string{
    $chunk=fn(string $type,string $body)=>pack('N',strlen($body)).$type.$body.pack('N',crc32($type.$body));
    return "\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',$w,$h,8,$colour,0,0,0)).($trns?$chunk('tRNS',"\x00"):'').$chunk('IEND','');
};
$fitJpg=fn(int $w,int $h):string=>"\xFF\xD8\xFF\xC0\x00\x11\x08".pack('n',$h).pack('n',$w)."\x03".str_repeat("\x01\x11\x00",3)."\xFF\xD9";
// An extended WebP header that says it carries alpha.
$fitWebpAlpha=function(int $w,int $h):string{
    $body='WEBP'.'VP8X'.pack('V',10).chr(0x10)."\0\0\0".substr(pack('V',$w-1),0,3).substr(pack('V',$h-1),0,3);
    return 'RIFF'.pack('V',strlen($body)).$body;
};
$fitFiles=[
    'demo/class.jpg'=>$fitJpg(1200,800),        // the demo photo the slot was captured with (3:2)
    'demo/wide-class.jpg'=>$fitJpg(1500,640),   // a photo cut to the box (2.3438)
    'demo/small-wide.jpg'=>$fitJpg(750,320),    // the box's shape, too narrow to stay sharp
    'demo/tall.jpg'=>$fitJpg(400,1200),
    'demo/icon.png'=>$fitPng(64,64,6),
    'demo/photo-square.jpg'=>$fitJpg(640,640),
    'demo/coach.png'=>$fitPng(600,900,6),       // the demo cut-out
    'demo/coach-rgb.png'=>$fitPng(600,900,2),   // a PNG with no alpha at all
    'demo/coach-trns.png'=>$fitPng(600,900,3,true),
    'demo/coach.jpg'=>$fitJpg(600,900),
    'demo/coach.webp'=>$fitWebpAlpha(600,900),
    'demo/logo.png'=>$fitPng(300,100,6),
    'demo/other-logo.png'=>$fitPng(600,200,6),
];
foreach($fitFiles as $name=>$bytes)file_put_contents($fitRoot.'/images/'.$name,$bytes);
$fitContract=(new ReflectionClass(QuickstartContract::class))->newInstanceWithoutConstructor();
$fitRootProperty=new ReflectionProperty(QuickstartContract::class,'root');
if (PHP_VERSION_ID < 80100) $fitRootProperty->setAccessible(true);
$fitRootProperty->setValue($fitContract,$fitRoot);
$fitCheck=new ReflectionMethod(QuickstartContract::class,'checkValue');
if (PHP_VERSION_ID < 80100) $fitCheck->setAccessible(true);
/** The refusal of a value, as [code, message] (null when taken). */
$fitAnswer=function(array $slot,string $value,array $params=[]) use ($fitContract,$fitCheck):?array{
    try{$fitCheck->invoke($fitContract,$slot,$value,$params);return null;}
    catch(ContractProblem $e){return [$e->reason,$e->getMessage()];}
    catch(Throwable $e){return [get_class($e),$e->getMessage()];}
};
$fitCode=fn(array $slot,string $value,array $params=[]):?string=>$fitAnswer($slot,$value,$params)[0]??null;

// 1. A contract without fit: every rule is the one it always was.
$oldPicture=['key'=>'gallery.1','type'=>'image','maxCharacters'=>255,'sample'=>'images/demo/class.jpg#joomlaImage://local-images/demo/class.jpg?width=1200&height=800'];
check('no fit: a picture of another shape is refused',$fitCode($oldPicture,'images/demo/photo-square.jpg'),'SLOT_IMAGE_INVALID');
check('no fit: the old aspect refusal says what it always said',$fitAnswer($oldPicture,'images/demo/tall.jpg'),['SLOT_IMAGE_INVALID','Image aspect ratio does not match its slot']);
file_put_contents($fitRoot.'/images/demo/class-copy.jpg',$fitJpg(600,400));
check('no fit: a smaller picture of the same shape is taken (no width floor)',$fitCode($oldPicture,'images/demo/class-copy.jpg'),null);
check('no fit: an opaque picture is taken anywhere',$fitCode(['sample'=>'images/demo/coach.png']+$oldPicture,'images/demo/coach.jpg'),null);
check('no fit: an icon slot takes a photo of its shape',$fitCode(['sample'=>'images/demo/icon.png']+$oldPicture,'images/demo/photo-square.jpg'),null);
$oldText=['key'=>'hero.0','type'=>'text','maxCharacters'=>40,'sample'=>'Demo title'];
check('no fit: words within the slot limit are taken',$fitCode($oldText,str_repeat('a',40)),null);
check('no fit: words over the slot limit say what they always said',$fitAnswer($oldText,str_repeat('a',41)),['SLOT_TOO_LONG','Content too long: hero.0']);
check('no fit: line breaks are not counted',$fitCode($oldText,"One\nTwo\nThree"),null);
check('a fit that is not an object is no fit',$fitCode($oldText+['fit'=>['a','b']],"One\nTwo\nThree"),null);

// 2. A photo slot: cut to the box's aspect within its tolerance, wide enough to stay sharp.
$photoFit=['measured'=>true,'kind'=>'photo','replace'=>'photo','transparent'=>false,'source'=>['width'=>1200,'height'=>800,'format'=>'jpg'],
    'boxes'=>['desktop'=>['width'=>704,'height'=>300],'phone'=>['width'=>358,'height'=>153]],'aspect'=>2.3467,'tolerance'=>0.02,'minWidth'=>1056,'crop'=>'cover'];
$photo=$oldPicture+['fit'=>$photoFit];
check('fit: a photo cut to the box is taken',$fitCode($photo,'images/demo/wide-class.jpg'),null);
check('fit: the box decides the shape, not the demo file',$fitCode($photo,'images/demo/photo-square.jpg'),'SLOT_IMAGE_INVALID');
$fitTall=$fitAnswer($photo,'images/demo/tall.jpg');
check('fit: the refusal says which shape to crop to',$fitTall,['SLOT_IMAGE_INVALID','Image aspect ratio does not match its slot: crop it to 2.3467 (width / height) keeping the subject: gallery.1']);
check('fit: a picture under minWidth is refused',$fitCode($photo,'images/demo/small-wide.jpg'),'SLOT_IMAGE_TOO_SMALL');
try{$fitCheck->invoke($fitContract,$photo,'images/demo/small-wide.jpg',[]);}catch(ContractProblem $e){check('fit: the size refusal carries the floor and the width',[$e->extra,$e->toArray()['severity']],[['limit'=>1056,'actual'=>750],'recoverable']);}
check('fit: a wider tolerance is read from the fit',$fitCode(['fit'=>['tolerance'=>0.2]+$photoFit]+$oldPicture,'images/demo/photo-square.jpg'),'SLOT_IMAGE_INVALID');
check('fit: the demo picture itself is always taken back',$fitCode($photo,'images/demo/class.jpg'),null);
// The demo explains itself: a picture of its shape passes the aspect rule, one as wide as it passes the width rule.
file_put_contents($fitRoot.'/images/demo/class-big.jpg',$fitJpg(2400,1600));
$ownSample=fn(array $extra,string $sample='')=>['fit'=>$extra+$photoFit]+($sample===''?[]:['sample'=>$sample])+$oldPicture;
check('fit: a picture of the demo shape and wider than the demo is taken though the box asks another shape',$fitCode($photo,'images/demo/class-big.jpg'),null);
check('fit: a picture of the demo shape but narrower than the demo is still too small',$fitCode($photo,'images/demo/class-copy.jpg'),'SLOT_IMAGE_TOO_SMALL');
file_put_contents($fitRoot.'/images/demo/class-twin.jpg',$fitJpg(1200,800));
check('fit: a width floor above the demo is met by a picture as wide as the demo (file written)',$fitCode($ownSample(['minWidth'=>2160]),'images/demo/class-twin.jpg'),null);
check('fit: narrower than the demo stays under that floor',$fitCode($ownSample(['minWidth'=>2160]),'images/demo/class-copy.jpg'),'SLOT_IMAGE_TOO_SMALL');
check('fit: a shape that is neither the box nor the demo is refused',$fitCode($photo,'images/demo/tall.jpg'),'SLOT_IMAGE_INVALID');
check('fit: a picture of the box shape narrower than the demo still follows the width floor',$fitCode($ownSample(['minWidth'=>2160]),'images/demo/small-wide.jpg'),'SLOT_IMAGE_TOO_SMALL');
$gone='images/demo/gone.jpg#joomlaImage://local-images/demo/gone.jpg?width=1200&height=800';
check('fit: the demo size is read from its stored value when its file is gone',$fitCode($ownSample(['source'=>null],$gone),'images/demo/class-big.jpg'),null);
check('fit: and the width floor too',$fitCode($ownSample(['source'=>null,'minWidth'=>2160],$gone),'images/demo/class-twin.jpg'),null);
check('fit: with no demo size anywhere the rules stand as measured',$fitCode($ownSample(['source'=>null],'images/demo/gone.jpg'),'images/demo/class-copy.jpg'),'SLOT_IMAGE_INVALID');
check('fit: the demo size can come from the fit source',$fitCode($ownSample([],'images/demo/gone.jpg'),'images/demo/class-big.jpg'),null);
check('fit: an unmeasured photo keeps the demo shape check',$fitCode($oldPicture+['fit'=>['measured'=>false,'kind'=>'photo','replace'=>'photo']],'images/demo/class-copy.jpg'),null);
check('fit: an unmeasured photo still refuses another shape',$fitCode($oldPicture+['fit'=>['measured'=>false,'kind'=>'photo','replace'=>'photo']],'images/demo/tall.jpg'),'SLOT_IMAGE_INVALID');

// 3. A kept picture (icon, avatar, signature, decoration, map): only when the request names it.
$iconFit=['measured'=>true,'kind'=>'icon','replace'=>'keep','transparent'=>true,'boxes'=>['desktop'=>['width'=>64,'height'=>64]],'aspect'=>1,'tolerance'=>0.02,'minWidth'=>96,'crop'=>'none',
    'source'=>['width'=>64,'height'=>64,'format'=>'png']];
$icon=['key'=>'features.3','type'=>'image','maxCharacters'=>255,'sample'=>'images/demo/icon.png','fit'=>$iconFit];
$fitKept=$fitAnswer($icon,'images/demo/photo-square.jpg');
check('keep: a photo in an icon slot is refused as a kept picture',$fitKept,['SLOT_PICTURE_KEPT','This picture is a icon the template keeps: leave it, or send replace_kept with the customer\'s words that ask for this very picture: features.3']);
check('keep: words for another slot do not open it',$fitCode($icon,'images/demo/photo-square.jpg',['replace_kept'=>['features.4'=>'Use my own icon for the second feature']]),'SLOT_PICTURE_KEPT');
check('keep: empty words do not open it',$fitCode($icon,'images/demo/photo-square.jpg',['replace_kept'=>['features.3'=>'  ']]),'SLOT_PICTURE_KEPT');
check('keep: evidence for the brief does not open it',$fitCode($icon,'images/demo/photo-square.jpg',['evidence'=>['features.3'=>'We teach boxing']]),'SLOT_PICTURE_KEPT');
$asked=['replace_kept'=>['features.3'=>'Put our square badge where the first feature icon is']];
check('keep: named by the request, it is then held to its fit (an opaque photo on a transparent icon)',$fitCode($icon,'images/demo/photo-square.jpg',$asked),'SLOT_IMAGE_OPAQUE');
file_put_contents($fitRoot.'/images/demo/badge.png',$fitPng(128,128,6));
check('keep: named by the request and fitting, it is taken',$fitCode($icon,'images/demo/badge.png',$asked),null);
check('keep: the demo icon is always taken back',$fitCode($icon,'images/demo/icon.png'),null);
foreach(['avatar','signature','decoration','map'] as $kind)
    check('keep: a '.$kind.' is kept as well',$fitCode(['fit'=>['kind'=>$kind,'transparent'=>false]+$iconFit]+$icon,'images/demo/photo-square.jpg'),'SLOT_PICTURE_KEPT');
check('keep: an unmeasured kept picture is still kept',$fitCode(['fit'=>['measured'=>false,'kind'=>'signature','replace'=>'keep']]+$icon,'images/demo/photo-square.jpg'),'SLOT_PICTURE_KEPT');

// 4. A cut-out slot: transparent ground required.
$cutFit=['measured'=>true,'kind'=>'cutout','replace'=>'keep','transparent'=>true,'source'=>['width'=>600,'height'=>900,'format'=>'png'],
    'boxes'=>['desktop'=>['width'=>400,'height'=>600]],'aspect'=>0.6667,'tolerance'=>0.02,'minWidth'=>600,'crop'=>'none'];
$cut=['key'=>'trainer.2','type'=>'image','maxCharacters'=>255,'sample'=>'images/demo/coach.png','fit'=>$cutFit];
$cutAsked=['replace_kept'=>['trainer.2'=>'Replace the trainer picture with our head coach']];
check('cut-out: a JPEG is refused as opaque',$fitAnswer($cut,'images/demo/coach.jpg',$cutAsked),['SLOT_IMAGE_OPAQUE','This slot shows a cut-out on a transparent ground and this image is opaque: ask the customer for a cut-out, or produce one by removing the background (PNG or WebP with transparency): trainer.2']);
check('cut-out: a PNG without alpha is refused as opaque',$fitCode($cut,'images/demo/coach-rgb.png',$cutAsked),'SLOT_IMAGE_OPAQUE');
check('cut-out: a PNG with an alpha channel is taken',$fitCode($cut,'images/demo/coach-trns.png',$cutAsked),null);
check('cut-out: a WebP with alpha is taken',$fitCode($cut,'images/demo/coach.webp',$cutAsked),null);
check('a photo slot that is not transparent takes a JPEG',$fitCode(['fit'=>['transparent'=>false,'replace'=>'photo','kind'=>'photo']+$cutFit]+$cut,'images/demo/coach.jpg'),null);
if(function_exists('imagecreatetruecolor')&&function_exists('imagepng')) {
    // With GD the corners are read as capture reads the demo: an alpha channel with opaque corners is no cut-out.
    $drawn=function(string $name,bool $clear) use ($fitRoot){
        $im=imagecreatetruecolor(600,900);imagealphablending($im,false);imagesavealpha($im,true);
        imagefill($im,0,0,imagecolorallocatealpha($im,200,40,40,$clear?127:0));
        imagefilledrectangle($im,200,200,400,700,imagecolorallocatealpha($im,20,20,20,0));
        imagepng($im,$fitRoot.'/images/demo/'.$name);
    };
    $drawn('coach-cut.png',true);$drawn('coach-boxed.png',false);
    check('cut-out (GD): transparent corners are a cut-out',$fitCode($cut,'images/demo/coach-cut.png',$cutAsked),null);
    check('cut-out (GD): an alpha channel with opaque corners is refused',$fitCode($cut,'images/demo/coach-boxed.png',$cutAsked),'SLOT_IMAGE_OPAQUE');
}

// 5. A logo fitted whole: its demo file's shape decides, not its wider box.
$logoFit=['measured'=>true,'kind'=>'logo','replace'=>'logo','transparent'=>true,'source'=>['width'=>300,'height'=>100,'format'=>'png'],
    'boxes'=>['desktop'=>['width'=>180,'height'=>40]],'aspect'=>4.5,'tolerance'=>0.02,'minWidth'=>270,'crop'=>'contain'];
$logo=['key'=>'header.logo','type'=>'image','maxCharacters'=>255,'sample'=>'images/demo/logo.png','fit'=>$logoFit];
check('logo: a logo of the demo file\'s shape is taken',$fitCode($logo,'images/demo/other-logo.png'),null);
check('logo: a logo is not a kept picture',$fitCode($logo,'images/demo/coach-trns.png'),'SLOT_IMAGE_INVALID');

// 6. Words: over the fit is refused with a request to rewrite, never cut.
$textFit=['measured'=>true,'boxes'=>['desktop'=>['width'=>400,'height'=>60],'phone'=>['width'=>300,'height'=>90]],'lines'=>['desktop'=>2,'phone'=>3],
    'maxChars'=>['desktop'=>30,'phone'=>24],'maxCharacters'=>24,'casing'=>'upper','overflow'=>'rewrite'];
$text=['fit'=>$textFit]+$oldText;
check('text: words within the fit are taken',$fitCode($text,str_repeat('b',24)),null);
check('text: words over the fit are refused with a request to rewrite them',$fitAnswer($text,'Strength classes for every level'),
    ['SLOT_TOO_LONG','Content too long for its box: rewrite it shorter, at most 24 characters, keeping its meaning (never cut it or add an ellipsis): hero.0']);
try{$fitCheck->invoke($fitContract,$text,str_repeat('c',30),[]);}catch(ContractProblem $e){check('text: the refusal carries the limit',$e->extra,['limit'=>24,'actual'=>30]);}
check('text: the slot\'s own limit still comes first',$fitAnswer($text,str_repeat('d',41))[1],'Content too long: hero.0');
check('text: more lines than the box holds are refused',$fitCode($text,"One\nTwo\nThree"),'SLOT_TOO_LONG');
check('text: as many lines as the box holds are taken',$fitCode($text,"One\nTwo"),null);
// A fit no wider than the demo's own words is the box of those words, not a limit: an inline label grows with them (the
// kit's rule, slot-limits.mjs). A line limit the demo itself breaks cannot refuse a value with fewer lines than the demo.
$fitWords=fn(string $sample,array $fit)=>['fit'=>['measured'=>true]+$fit,'key'=>'label.0','type'=>'text','maxCharacters'=>2048,'sample'=>$sample];
check('text: a limit that is the box of the demo words does not refuse a longer label',$fitCode($fitWords('MADEUP7',['maxCharacters'=>7]),'MADEUP-8'),null);
check('text: nor at exactly the demo length plus three',$fitCode($fitWords('MADEUP7',['maxCharacters'=>10]),str_repeat('c',30)),null);
check('text: one above that it is a limit again',$fitCode($fitWords('MADEUP7',['maxCharacters'=>11]),str_repeat('c',12)),'SLOT_TOO_LONG');
check('text: a limit well above the demo still refuses, with the same code and words',$fitAnswer($fitWords('Made-up title',['maxCharacters'=>28]),str_repeat('d',40)),
    ['SLOT_TOO_LONG','Content too long for its box: rewrite it shorter, at most 28 characters, keeping its meaning (never cut it or add an ellipsis): label.0']);
check('text: the demo length is what a visitor reads, not its markers',$fitCode($fitWords('((p))Made-up((/p))'."\n\n".'((p))title((/p))',['maxCharacters'=>16]),str_repeat('d',17)),null);
check('text: and markers do not widen it',$fitCode($fitWords('((p class="made-up"))Made-up((/p))',['maxCharacters'=>11]),str_repeat('d',12)),'SLOT_TOO_LONG');
check('text: the box of the demo words also covers maxChars',$fitCode($fitWords('MADEUP7',['maxChars'=>['desktop'=>7,'phone'=>14]]),'MADEUP-8'),null);
check('text: the slot\'s own limit still holds when the fit\'s does not',$fitCode(['maxCharacters'=>40]+$fitWords('MADEUP7',['maxCharacters'=>7]),str_repeat('c',41)),'SLOT_TOO_LONG');
$fitSix="One\nTwo\nThree\nFour\nFive\nSix";
check('text: fewer lines than a demo that breaks its line limit are taken',$fitCode($fitWords($fitSix,['lines'=>['desktop'=>1]]),"One\nTwo\nThree"),null);
check('text: as many lines as that demo are taken',$fitCode($fitWords($fitSix,['lines'=>['desktop'=>1]]),"1\n2\n3\n4\n5\n6"),null);
check('text: more lines than that demo are refused, at the demo\'s count',$fitAnswer($fitWords($fitSix,['lines'=>['desktop'=>1]]),"1\n2\n3\n4\n5\n6\n7"),
    ['SLOT_TOO_LONG','Content has more lines than its box holds: rewrite it in at most 6 line(s): label.0']);
check('text: three lines on a one line demo are still refused, with the same code and words',$fitAnswer($fitWords('Made-up',['lines'=>['desktop'=>1]]),"One\nTwo\nThree"),
    ['SLOT_TOO_LONG','Content has more lines than its box holds: rewrite it in at most 1 line(s): label.0']);
$fitDirect=function(array $fit,string $value,?string $sample=null):?string{
    try{ $sample===null?SlotFit::checkText($fit,'label.0',$value,fn($code,$message)=>new RuntimeException($code)):SlotFit::checkText($fit,'label.0',$value,fn($code,$message)=>new RuntimeException($code),$sample); return null; }
    catch(RuntimeException $e){ return $e->getMessage(); }
};
check('text: with no sample the character limit stays as it was',$fitDirect(['measured'=>true,'maxCharacters'=>7],'MADEUP-8'),'SLOT_TOO_LONG');
check('text: with no sample the line limit stays as it was',$fitDirect(['measured'=>true,'lines'=>['desktop'=>1]],"One\nTwo"),'SLOT_TOO_LONG');
check('text: with a sample the same two are taken',[$fitDirect(['measured'=>true,'maxCharacters'=>7],'MADEUP-8','MADEUP7'),$fitDirect(['measured'=>true,'lines'=>['desktop'=>1]],"One\nTwo","A\nB")],[null,null]);
check('text: maxChars decides when maxCharacters is missing',$fitCode(['fit'=>array_diff_key($textFit,['maxCharacters'=>1])]+$oldText,str_repeat('e',25)),'SLOT_TOO_LONG');
// The fit counts the characters a visitor reads: emphasis codes and HTML tags are not words, an entity is one character,
// a line break is one space, every whitespace run is one.
$fitSeen=fn(int $limit)=>['fit'=>['measured'=>true,'maxCharacters'=>$limit],'key'=>'heading.0','type'=>'text','maxCharacters'=>2048,'sample'=>'Hi'];
check('text: emphasis codes are not counted (44 raw, 21 seen, limit 21)',$fitCode($fitSeen(21),'Why((br/))((span))fennels((/span)) choose us'),null);
check('text: an entity is one character',$fitCode($fitSeen(14),'Fennel &amp; co &#8217;s'),null);
check('text: a line break counts as one space, not nothing',$fitCode($fitSeen(12),'Fennel((br/))groves'),'SLOT_TOO_LONG');
check('text: and as one space only',$fitCode($fitSeen(13),'Fennel((br/))groves'),null);
check('text: an inline emphasis inside a word adds no space',$fitCode($fitSeen(6),'Fen((span))nel((/span))'),null);
check('text: visible words over the fit are still refused',$fitAnswer($fitSeen(21),'Why((br/))((span))fennel growers((/span)) choose us'),
    ['SLOT_TOO_LONG','Content too long for its box: rewrite it shorter, at most 21 characters, keeping its meaning (never cut it or add an ellipsis): heading.0']);
$fitSeenExtra=null;try{$fitCheck->invoke($fitContract,$fitSeen(10),'((span))Fennel((/span)) groves',[]);}catch(ContractProblem $e){$fitSeenExtra=$e->extra;}
check('text: the refusal carries the visible length',$fitSeenExtra,['limit'=>10,'actual'=>13]);
// A contract write still refuses real markup before it is measured; SlotFit itself counts tags as it counts codes.
check('text: a contract write still refuses real markup',$fitCode($fitSeen(21),'Why<br/>fennels choose us'),'SLOT_NOT_CONTENT');
check('text: HTML tags are not counted',$fitDirect(['measured'=>true,'maxCharacters'=>21],'Why<br/><span class="hl">fennels</span> choose us'),null);
check('text: a self-closing tag with a space is one space',$fitDirect(['measured'=>true,'maxCharacters'=>21],'Why<br />fennels choose us'),null);
check('text: visible words between tags over the fit are still refused',$fitDirect(['measured'=>true,'maxCharacters'=>21],'Why<br/><span>fennel growers</span> choose us'),'SLOT_TOO_LONG');
check('text: tags written as text are still words',$fitCode($fitSeen(10),'&lt;span&gt;Fennel'),'SLOT_TOO_LONG');
// The slot's own maxCharacters is sized from its demo words, so it counts what a visitor reads too: a heading whose codes
// make it 45 characters raw and 22 on the page fits a 42 character slot (JA Boxing's module headings), with or without fit.
$fitHeading=['key'=>'module-9.20','entity'=>'module-9','column'=>'params','type'=>'text','maxCharacters'=>42,'sample'=>'THE((br/)) ((span))DRILL((/span))'];
$fitCodes='Why((br/))((span))quillets((/span)) choose us';
check('slot limit: the codes make the words 45 characters raw',mb_strlen($fitCodes),45);
check('slot limit: codes over the slot limit raw, visible words within it, are taken',$fitCode($fitHeading,$fitCodes),null);
check('slot limit: and taken beside a fit too',$fitCode(['fit'=>['measured'=>true,'maxCharacters'=>468]]+$fitHeading,$fitCodes),null);
$fitSlotExtra=null;try{$fitCheck->invoke($fitContract,['maxCharacters'=>20]+$fitHeading,$fitCodes,[]);}catch(ContractProblem $e){$fitSlotExtra=[$e->reason,$e->getMessage(),$e->extra];}
check('slot limit: visible words over it are refused as always, with the visible length',$fitSlotExtra,
    ['SLOT_TOO_LONG','Content too long: module-9.20',['limit'=>20,'actual'=>22]]);
check('slot limit: plain words over it are refused as always',$fitCode($fitHeading,str_repeat('q',43)),'SLOT_TOO_LONG');
// A column of fixed width (a module title is VARCHAR(100)) stores the raw string, so there the limit still counts it.
$fitTitleExtra=null;try{$fitCheck->invoke($fitContract,['column'=>'title','maxCharacters'=>42]+$fitHeading,$fitCodes,[]);}catch(ContractProblem $e){$fitTitleExtra=$e->extra;}
check('slot limit: a fixed width column still counts the raw string',$fitTitleExtra,['limit'=>42,'actual'=>45]);
// A translation is held to the same count before it is written.
$fitEngine=(new ReflectionClass(Engine::class))->newInstanceWithoutConstructor();
$fitTranslation=new ReflectionMethod(Engine::class,'translationProblems');
if (PHP_VERSION_ID < 80100) $fitTranslation->setAccessible(true);
$fitTranslated=fn(array $slot,string $value)=>array_column($fitTranslation->invoke($fitEngine,[['source'=>$slot['sample']]+$slot],[$slot['key']=>$value]),'problem');
check('translation: codes over the slot limit raw, visible words within it, are taken',$fitTranslated($fitHeading,$fitCodes),[]);
check('translation: visible words over it are refused with the visible length',$fitTranslated(['maxCharacters'=>20]+$fitHeading,$fitCodes),
    ['module-9.20: is 22 characters, over the slot limit of 20']);
check('translation: a fixed width column still counts the raw string',$fitTranslated(['column'=>'title']+$fitHeading,$fitCodes),
    ['module-9.20: is 45 characters, over the slot limit of 42']);
check('text: an unmeasured text keeps the slot limit only',$fitCode(['fit'=>['measured'=>false]]+$oldText,str_repeat('f',40)),null);
check('text: the demo words are always taken back, even over a fit',$fitCode(['fit'=>['maxCharacters'=>4,'maxChars'=>['desktop'=>4]]+$textFit]+$oldText,'Demo title'),null);

// 7. What the slot list carries.
check('the slot list carries a compact picture fit',SlotFit::compact($photoFit),
    ['measured'=>true,'kind'=>'photo','replace'=>'photo','transparent'=>false,'aspect'=>2.3467,'tolerance'=>0.02,'minWidth'=>1056,'crop'=>'cover']);
check('the slot list carries a compact text fit',SlotFit::compact($textFit),
    ['measured'=>true,'lines'=>['desktop'=>2,'phone'=>3],'maxChars'=>['desktop'=>30,'phone'=>24],'maxCharacters'=>24,'casing'=>'upper','overflow'=>'rewrite']);
check('an unmeasured fit stays as small as it is',SlotFit::compact(['measured'=>false,'kind'=>'map','replace'=>'keep','transparent'=>null,'source'=>['width'=>10,'height'=>10]]),
    ['measured'=>false,'kind'=>'map','replace'=>'keep']);
foreach(['SLOT_PICTURE_KEPT','SLOT_IMAGE_OPAQUE','SLOT_IMAGE_TOO_SMALL'] as $code)
    check($code.' can be fixed by sending something else',ContractProblem::severityOf($code),'recoverable');
check('the guidance names the request field and the cut-out formats',
    [strpos(SlotFit::GUIDANCE,'replace_kept')!==false,strpos(SlotFit::GUIDANCE,'PNG or WebP')!==false,strpos(SlotFit::GUIDANCE,'never cut them')!==false],[true,true,true]);
