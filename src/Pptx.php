<?php
declare(strict_types=1);

namespace App;

/**
 * Minimal PowerPoint (.pptx) writer: 16:9 slides with text boxes, filled shapes and images.
 * Coordinates are in points on a 960 × 540 slide.
 */
final class Pptx
{
    private const EMU = 12700;
    private const NS = 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"';

    /** @var array<int, array{shapes: string[], rels: array<string, string>, bg: ?string}> */
    private array $slides = [];
    /** @var array<string, string> media file name => bytes */
    private array $media = [];
    private int $nextId = 2;

    public function __construct(private string $title = 'Report', private string $font = 'Calibri')
    {
    }

    public function addSlide(?string $background = null): int
    {
        $this->slides[] = ['shapes' => [], 'rels' => [], 'bg' => $background];
        return count($this->slides) - 1;
    }

    private static function emu(float $pt): int
    {
        return (int) round($pt * self::EMU);
    }

    private static function hex(string $c): string
    {
        return strtoupper(ltrim($c, '#'));
    }

    private static function x(string $s): string
    {
        // Strip characters XML 1.0 forbids, then escape.
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function xfrm(float $x, float $y, float $w, float $h): string
    {
        return sprintf('<a:xfrm><a:off x="%d" y="%d"/><a:ext cx="%d" cy="%d"/></a:xfrm>', self::emu($x), self::emu($y), self::emu($w), self::emu($h));
    }

    /** Filled (rounded) rectangle. */
    public function rect(int $slide, float $x, float $y, float $w, float $h, string $fill, bool $rounded = false, ?string $line = null): void
    {
        $id = $this->nextId++;
        $geom = $rounded ? '<a:prstGeom prst="roundRect"><a:avLst><a:gd name="adj" fmla="val 8000"/></a:avLst></a:prstGeom>' : '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>';
        $ln = $line ? '<a:ln w="9525"><a:solidFill><a:srgbClr val="' . self::hex($line) . '"/></a:solidFill></a:ln>' : '<a:ln><a:noFill/></a:ln>';
        $this->slides[$slide]['shapes'][] = '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="Shape ' . $id . '"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
            . '<p:spPr>' . $this->xfrm($x, $y, $w, $h) . $geom . '<a:solidFill><a:srgbClr val="' . self::hex($fill) . '"/></a:solidFill>' . $ln . '</p:spPr>'
            . '<p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:endParaRPr lang="en-US"/></a:p></p:txBody></p:sp>';
    }

    /**
     * Text box. Options: size (pt), bold, color, align (l|ctr|r), valign (t|ctr|b), fill, lines (array of [text, size, bold, color] for multi-style paragraphs).
     */
    public function text(int $slide, float $x, float $y, float $w, float $h, string $text, array $o = []): void
    {
        $id = $this->nextId++;
        $paras = $o['lines'] ?? array_map(fn($line) => [$line, $o['size'] ?? 14, $o['bold'] ?? false, $o['color'] ?? '#15181e'], preg_split('/\R/u', $text));
        $body = '';
        foreach ($paras as [$t, $size, $bold, $color]) {
            $body .= '<a:p><a:pPr algn="' . ($o['align'] ?? 'l') . '"/>'
                . '<a:r><a:rPr lang="en-US" sz="' . (int) round($size * 100) . '"' . ($bold ? ' b="1"' : '') . ' dirty="0">'
                . '<a:solidFill><a:srgbClr val="' . self::hex($color) . '"/></a:solidFill><a:latin typeface="' . self::x($this->font) . '"/></a:rPr>'
                . '<a:t>' . self::x($t) . '</a:t></a:r></a:p>';
        }
        $fill = isset($o['fill']) ? '<a:solidFill><a:srgbClr val="' . self::hex($o['fill']) . '"/></a:solidFill>' : '<a:noFill/>';
        $this->slides[$slide]['shapes'][] = '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="Text ' . $id . '"/><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>'
            . '<p:spPr>' . $this->xfrm($x, $y, $w, $h) . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>' . $fill . '</p:spPr>'
            . '<p:txBody><a:bodyPr wrap="square" lIns="45720" tIns="22860" rIns="45720" bIns="22860" anchor="' . ($o['valign'] ?? 't') . '"><a:normAutofit/></a:bodyPr><a:lstStyle/>'
            . $body . '</p:txBody></p:sp>';
    }

    /** Image from bytes ('png' or 'jpeg'). */
    public function image(int $slide, string $bytes, string $ext, float $x, float $y, float $w, float $h): void
    {
        $name = 'image' . (count($this->media) + 1) . '.' . $ext;
        $this->media[$name] = $bytes;
        $rid = 'rId' . (count($this->slides[$slide]['rels']) + 2);
        $this->slides[$slide]['rels'][$rid] = '../media/' . $name;
        $id = $this->nextId++;
        $this->slides[$slide]['shapes'][] = '<p:pic><p:nvPicPr><p:cNvPr id="' . $id . '" name="Picture ' . $id . '"/><p:cNvPicPr><a:picLocks noChangeAspect="1"/></p:cNvPicPr><p:nvPr/></p:nvPicPr>'
            . '<p:blipFill><a:blip r:embed="' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></p:blipFill>'
            . '<p:spPr>' . $this->xfrm($x, $y, $w, $h) . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr></p:pic>';
    }

    public function output(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pptx');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $n = count($this->slides);

        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Default Extension="png" ContentType="image/png"/><Default Extension="jpeg" ContentType="image/jpeg"/>'
            . '<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
            . '<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>'
            . '<Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>'
            . '<Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
        for ($i = 1; $i <= $n; $i++) {
            $ct .= '<Override PartName="/ppt/slides/slide' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>';
        }
        $zip->addFromString('[Content_Types].xml', $ct . '</Types>');

        $rel = fn(array $rels) => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . implode('', array_map(fn($r) => '<Relationship Id="' . $r[0] . '" Type="http://schemas.openxmlformats.org/' . $r[1] . '" Target="' . $r[2] . '"/>', $rels)) . '</Relationships>';

        $zip->addFromString('_rels/.rels', $rel([
            ['rId1', 'officeDocument/2006/relationships/officeDocument', 'ppt/presentation.xml'],
            ['rId2', 'package/2006/relationships/metadata/core-properties', 'docProps/core.xml'],
            ['rId3', 'officeDocument/2006/relationships/extended-properties', 'docProps/app.xml'],
        ]));
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . self::x($this->title) . '</dc:title><dc:creator>' . self::x(Config::get('app_name', 'MixCreate')) . '</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified></cp:coreProperties>');
        $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>' . self::x(Config::get('app_name', 'MixCreate')) . '</Application><Slides>' . $n . '</Slides></Properties>');

        $sldIds = '';
        $presRels = [['rId1', 'officeDocument/2006/relationships/slideMaster', 'slideMasters/slideMaster1.xml']];
        for ($i = 1; $i <= $n; $i++) {
            $sldIds .= '<p:sldId id="' . (255 + $i) . '" r:id="rId' . ($i + 1) . '"/>';
            $presRels[] = ['rId' . ($i + 1), 'officeDocument/2006/relationships/slide', 'slides/slide' . $i . '.xml'];
        }
        $presRels[] = ['rId' . ($n + 2), 'officeDocument/2006/relationships/theme', 'theme/theme1.xml'];
        $zip->addFromString('ppt/presentation.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:presentation ' . self::NS . ' saveSubsetFonts="1">'
            . '<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst><p:sldIdLst>' . $sldIds . '</p:sldIdLst>'
            . '<p:sldSz cx="12192000" cy="6858000"/><p:notesSz cx="6858000" cy="9144000"/><p:defaultTextStyle><a:defPPr><a:defRPr lang="en-US"/></a:defPPr></p:defaultTextStyle></p:presentation>');
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $rel($presRels));

        $emptyTree = '<p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>';
        $zip->addFromString('ppt/slideMasters/slideMaster1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:sldMaster ' . self::NS . '>'
            . '<p:cSld><p:bg><p:bgRef idx="1001"><a:schemeClr val="bg1"/></p:bgRef></p:bg>' . $emptyTree . '</p:spTree></p:cSld>'
            . '<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>'
            . '<p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/></p:sldLayoutIdLst>'
            . '<p:txStyles><p:titleStyle><a:lvl1pPr><a:defRPr sz="3200"/></a:lvl1pPr></p:titleStyle><p:bodyStyle><a:lvl1pPr><a:defRPr sz="1800"/></a:lvl1pPr></p:bodyStyle><p:otherStyle><a:lvl1pPr><a:defRPr sz="1800"/></a:lvl1pPr></p:otherStyle></p:txStyles></p:sldMaster>');
        $zip->addFromString('ppt/slideMasters/_rels/slideMaster1.xml.rels', $rel([
            ['rId1', 'officeDocument/2006/relationships/slideLayout', '../slideLayouts/slideLayout1.xml'],
            ['rId2', 'officeDocument/2006/relationships/theme', '../theme/theme1.xml'],
        ]));
        $zip->addFromString('ppt/slideLayouts/slideLayout1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:sldLayout ' . self::NS . ' type="blank" preserve="1">'
            . '<p:cSld name="Blank">' . $emptyTree . '</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sldLayout>');
        $zip->addFromString('ppt/slideLayouts/_rels/slideLayout1.xml.rels', $rel([['rId1', 'officeDocument/2006/relationships/slideMaster', '../slideMasters/slideMaster1.xml']]));
        $zip->addFromString('ppt/theme/theme1.xml', $this->theme());

        foreach ($this->slides as $i => $s) {
            $bg = $s['bg'] ? '<p:bg><p:bgPr><a:solidFill><a:srgbClr val="' . self::hex($s['bg']) . '"/></a:solidFill><a:effectLst/></p:bgPr></p:bg>' : '';
            $zip->addFromString('ppt/slides/slide' . ($i + 1) . '.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:sld ' . self::NS . '>'
                . '<p:cSld>' . $bg . $emptyTree . implode('', $s['shapes']) . '</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>');
            $rels = [['rId1', 'officeDocument/2006/relationships/slideLayout', '../slideLayouts/slideLayout1.xml']];
            foreach ($s['rels'] as $rid => $target) {
                $rels[] = [$rid, 'officeDocument/2006/relationships/image', $target];
            }
            $zip->addFromString('ppt/slides/_rels/slide' . ($i + 1) . '.xml.rels', $rel($rels));
        }
        foreach ($this->media as $name => $bytes) {
            $zip->addFromString('ppt/media/' . $name, $bytes);
        }
        $zip->close();
        $data = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $data;
    }

    private function theme(): string
    {
        $f = self::x($this->font);
        $fill3 = '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill>';
        $ln = '<a:ln w="9525"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="MixCreate"><a:themeElements>'
            . '<a:clrScheme name="MixCreate"><a:dk1><a:srgbClr val="15181E"/></a:dk1><a:lt1><a:srgbClr val="FFFFFF"/></a:lt1><a:dk2><a:srgbClr val="374151"/></a:dk2><a:lt2><a:srgbClr val="F4F5F8"/></a:lt2>'
            . '<a:accent1><a:srgbClr val="4F46E5"/></a:accent1><a:accent2><a:srgbClr val="0891B2"/></a:accent2><a:accent3><a:srgbClr val="059669"/></a:accent3><a:accent4><a:srgbClr val="CA8A04"/></a:accent4>'
            . '<a:accent5><a:srgbClr val="EA580C"/></a:accent5><a:accent6><a:srgbClr val="DB2777"/></a:accent6><a:hlink><a:srgbClr val="4F46E5"/></a:hlink><a:folHlink><a:srgbClr val="7C3AED"/></a:folHlink></a:clrScheme>'
            . '<a:fontScheme name="MixCreate"><a:majorFont><a:latin typeface="' . $f . '"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont><a:minorFont><a:latin typeface="' . $f . '"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont></a:fontScheme>'
            . '<a:fmtScheme name="MixCreate"><a:fillStyleLst>' . $fill3 . '</a:fillStyleLst><a:lnStyleLst>' . $ln . $ln . $ln . '</a:lnStyleLst>'
            . '<a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst>'
            . '<a:bgFillStyleLst>' . $fill3 . '</a:bgFillStyleLst></a:fmtScheme></a:themeElements><a:objectDefaults/><a:extraClrSchemeLst/></a:theme>';
    }
}
