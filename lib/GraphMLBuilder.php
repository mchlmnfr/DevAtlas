<?php

/**
 * Filename: GraphMLBuilder.php
 * Purpose: Provide graph mlbuilder processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/**
 * DevAtlas :: GraphMLBuilder.php
 *
 * Builds valid yEd-compatible GraphML (.graphml) XML from a diagram's
 * nodes and edges. Output opens natively in yEd Graph Editor with
 * proper shapes, colors, labels, and edge arrows/cardinality labels.
 *
 * yEd reads its visual data from the "yfiles.type" extension keys
 * (y:ShapeNode, y:PolyLineEdge, y:GroupNode, y:GenericNode) inside the
 * standard GraphML <data> elements. This class hand-builds that XML
 * without any external library, keeping the tool fully offline.
 */

class GraphMLBuilder
{
    private array $nodes = [];
    // node_key => node row
    private array $edges = [];
    private string $diagramType;
    private string $diagramName;

    public function __construct(string $diagramType, string $diagramName, array $nodes, array $edges)
    {
        $this->diagramType = strtoupper($diagramType);
        $this->diagramName = $diagramName;
        foreach ($nodes as $n)
        {
            if (!preg_match('/^#[A-Fa-f0-9]{6}$/D', $n['fill_color'] ?? ''))
            {
                $n['fill_color'] = '#FFFFFF';
            }
            $this->nodes[$n['node_key']] = $n;
        }
        $this->edges = $edges;
    }

    /** Entry point: returns the full GraphML XML document as a string. */
    public function Build(): string
    {
        $xml = $this->Header();
        $xml .= $this->GraphOpen();

        foreach ($this->nodes as $node)
        {
            // Skip child rows here; ERD attributes are emitted nested inside their parent entity.
            if (!empty($node['parent_node_key']))
            {
                continue;
            }
            $xml .= $this->RenderNode($node);
        }

        foreach ($this->edges as $edge)
        {
            $xml .= $this->RenderEdge($edge);
        }

        $xml .= $this->GraphClose();
        $xml .= $this->Footer();
        return $xml;
    }

    // -------------------------------------------------------------
    // Document scaffolding
    // -------------------------------------------------------------

    private function Header(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<graphml xmlns="http://graphml.graphdrawing.org/xmlns"
         xmlns:java="http://www.yworks.com/xml/yfiles-common/1.0/java"
         xmlns:sys="http://www.yworks.com/xml/yfiles-common/markup/primitives/2.0"
         xmlns:x="http://www.yworks.com/xml/yfiles-common/markup/2.0"
         xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xmlns:y="http://www.yworks.com/xml/graphml"
         xmlns:yed="http://www.yworks.com/xml/yed/3"
         xsi:schemaLocation="http://graphml.graphdrawing.org/xmlns http://www.yworks.com/xml/schema/graphml/1.1/ygraphml.xsd">
  <key attr.name="Description" attr.type="string" for="graph" id="d0"/>
  <key for="port" id="d1" yfiles.type="portgraphics"/>
  <key for="port" id="d2" yfiles.type="portgeometry"/>
  <key for="port" id="d3" yfiles.type="portuserdata"/>
  <key attr.name="url" attr.type="string" for="node" id="d4"/>
  <key attr.name="description" attr.type="string" for="node" id="d5"/>
  <key for="node" id="d6" yfiles.type="nodegraphics"/>
  <key for="graphml" id="d7" yfiles.type="resources"/>
  <key attr.name="url" attr.type="string" for="edge" id="d8"/>
  <key attr.name="description" attr.type="string" for="edge" id="d9"/>
  <key for="edge" id="d10" yfiles.type="edgegraphics"/>

XML;
    }

    private function GraphOpen(): string
    {
        $desc = htmlspecialchars($this->diagramName, ENT_QUOTES, 'UTF-8');
        return "  <graph edgedefault=\"directed\" id=\"G\">\n"
             . "    <data key=\"d0\">{$desc} ({$this->diagramType})</data>\n";
    }

    private function GraphClose(): string
    {
        return "  </graph>\n";
    }

    private function Footer(): string
    {
        return "</graphml>\n";
    }

    // -------------------------------------------------------------
    // Node rendering — dispatches per diagram type / subtype
    // -------------------------------------------------------------

    private function RenderNode(array $node): string
    {
        $id = $this->XmlEsc($node['node_key']);
        $label = $this->XmlEsc($node['label']);
        $x = (float)($node['pos_x'] ?? 0);
        $y = (float)($node['pos_y'] ?? 0);
        $w = (float)($node['width'] ?? 140);
        $h = (float)($node['height'] ?? 40);
        $fill = $node['fill_color'] ?: '#FFFFFF';
        $desc = $node['detail'] ?? '';

        // ERD entity with nested attribute rows -> render as a yEd group node
        if ($this->diagramType === 'ERD' && $node['node_subtype'] === 'entity')
        {
            return $this->RenderErdEntity($node, $x, $y, $w, $h, $fill);
        }

        $shape = $this->ShapeForNode($node);

        $out = "    <node id=\"{$id}\">\n";
        if ($desc !== '')
        {
            $out .= "      <data key=\"d5\">" . $this->XmlEsc($desc) . "</data>\n";
        }
        $out .= "      <data key=\"d6\">\n";
        $out .= $this->ShapeXml($shape, $x, $y, $w, $h, $fill, $label);
        $out .= "      </data>\n";
        $out .= "    </node>\n";
        return $out;
    }

    /** ERD entities render as a yEd GroupNode with each attribute as a nested child row. */
    private function RenderErdEntity(array $entity, float $x, float $y, float $w, float $h, string $fill): string
    {
        $entityId = $this->XmlEsc($entity['node_key']);
        $meta = is_string($entity['erd_meta'] ?? null) ? (json_decode($entity['erd_meta'], true) ?: []) : ($entity['erd_meta'] ?? []);
        $label = $this->XmlEsc((empty($meta['schema_name']) ? '' : $meta['schema_name'] . '.') . $entity['label']);

        // gather attribute children for this entity, in insertion order
        $children = array_values(array_filter($this->nodes, function ($n) use ($entity)
        {
            return ($n['parent_node_key'] ?? null) === $entity['node_key'];
        }));

        $rowHeight = 24;
        $groupHeight = max($h, 30 + (count($children) * $rowHeight) + 10);

        $out = "    <node id=\"{$entityId}\" yfiles.foldertype=\"group\">\n";
        $out .= "      <data key=\"d6\">\n";
        $out .= "        <y:ProxyAutoBoundsNode>\n";
        $out .= "          <y:Realizers active=\"0\">\n";
        $out .= "            <y:GroupNode>\n";
        $out .= "              <y:Geometry height=\"{$groupHeight}\" width=\"{$w}\" x=\"{$x}\" y=\"{$y}\"/>\n";
        $out .= "              <y:Fill color=\"{$fill}\" transparent=\"false\"/>\n";
        $out .= "              <y:BorderStyle color=\"#000000\" type=\"line\" width=\"1.0\"/>\n";
        $out .= "              <y:NodeLabel alignment=\"center\" autoSizePolicy=\"node_width\" backgroundColor=\"#B7C9E3\" fontStyle=\"bold\" modelName=\"internal\" modelPosition=\"t\" horizontalTextPosition=\"center\" verticalTextPosition=\"bottom\">{$label}</y:NodeLabel>\n";
        $out .= "              <y:Shape type=\"rectangle\"/>\n";
        $out .= "              <y:State closed=\"false\"/>\n";
        $out .= "            </y:GroupNode>\n";
        $out .= "          </y:Realizers>\n";
        $out .= "        </y:ProxyAutoBoundsNode>\n";
        $out .= "      </data>\n";
        $out .= "      <graph edgedefault=\"directed\" id=\"{$entityId}:\">\n";

        $rowY = 4;
        foreach ($children as $attr)
        {
            $attrId = $this->XmlEsc($attr['node_key']);
            $pk = (stripos($attr['detail'] ?? '', 'PK') !== false) ? '🔑 ' : '';
            $attrLabel = $this->XmlEsc($pk . $attr['label'] . (!empty($attr['detail']) ? '  : ' . $attr['detail'] : ''));
            $out .= "        <node id=\"{$attrId}\">\n";
            $out .= "          <data key=\"d6\">\n";
            $out .= "            <y:ShapeNode>\n";
            $out .= "              <y:Geometry height=\"{$rowHeight}\" width=\"" . ($w - 8) . "\" x=\"" . ($x + 4) . "\" y=\"" . ($y + 30 + $rowY) . "\"/>\n";
            $out .= "              <y:Fill color=\"#FFFFFF\" transparent=\"false\"/>\n";
            $out .= "              <y:BorderStyle color=\"#999999\" type=\"line\" width=\"1.0\"/>\n";
            $out .= "              <y:NodeLabel alignment=\"left\" fontSize=\"11\" modelName=\"internal\" modelPosition=\"l\">{$attrLabel}</y:NodeLabel>\n";
            $out .= "              <y:Shape type=\"rectangle\"/>\n";
            $out .= "            </y:ShapeNode>\n";
            $out .= "          </data>\n";
            $out .= "        </node>\n";
            $rowY += $rowHeight;
        }

        $out .= "      </graph>\n";
        $out .= "    </node>\n";
        return $out;
    }

    /** Decide the yEd shape/style to use per diagram type + node subtype. */
    private function ShapeForNode(array $node): array
    {
        $subtype = $node['node_subtype'];

        switch ($this->diagramType)
        {
            case 'DFD':
                return match ($subtype)
                {
                    'external_entity' => ['kind' => 'rect',  'shape' => 'rectangle', 'fill' => $node['fill_color'] ?: '#F5B942'],
                    'process' => ['kind' => 'rect',  'shape' => 'roundrectangle', 'fill' => $node['fill_color'] ?: '#8FBCE6'],
                    'data_store' => ['kind' => 'store', 'shape' => 'rectangle', 'fill' => $node['fill_color'] ?: '#C6E5B3'],
                    default => ['kind' => 'rect',  'shape' => 'rectangle', 'fill' => $node['fill_color'] ?: '#FFFFFF'],
                };
            case 'SFD':
                return match ($subtype)
                {
                    'system' => ['kind' => 'rect', 'shape' => 'rectangle', 'fill' => $node['fill_color'] ?: '#B7C9E3'],
                    'component' => ['kind' => 'rect', 'shape' => 'roundrectangle', 'fill' => $node['fill_color'] ?: '#D9EAD3'],
                    'database' => ['kind' => 'store', 'shape' => 'rectangle', 'fill' => $node['fill_color'] ?: '#FFF2CC'],
                    'interface' => ['kind' => 'ellipse', 'shape' => 'ellipse', 'fill' => $node['fill_color'] ?: '#EAD1DC'],
                    default => ['kind' => 'rect', 'shape' => 'rectangle', 'fill' => $node['fill_color'] ?: '#FFFFFF'],
                };
            case 'PFD':
                return match ($subtype)
                {
                    'start' => ['kind' => 'ellipse', 'shape' => 'ellipse', 'fill' => $node['fill_color'] ?: '#C6E5B3'],
                    'end' => ['kind' => 'ellipse', 'shape' => 'ellipse', 'fill' => $node['fill_color'] ?: '#F4A6A6'],
                    'decision' => ['kind' => 'diamond', 'shape' => 'diamond', 'fill' => $node['fill_color'] ?: '#FFF2CC'],
                    'step' => ['kind' => 'rect', 'shape' => 'roundrectangle', 'fill' => $node['fill_color'] ?: '#B7C9E3'],
                    default => ['kind' => 'rect', 'shape' => 'rectangle', 'fill' => $node['fill_color'] ?: '#FFFFFF'],
                };
            default: // ERD attribute fallback (standalone, no parent)
                return ['kind' => 'rect', 'shape' => 'rectangle', 'fill' => $node['fill_color'] ?: '#FFFFFF'];
        }
    }

    private function ShapeXml(array $shape, float $x, float $y, float $w, float $h, string $fill, string $label): string
    {
        $color = $shape['fill'] ?? $fill;

        if ($shape['kind'] === 'store')
        {
            // DFD/SFD data store: open-ended rectangle rendered as two parallel lines look
            // (approximated in yEd with a rectangle + heavier top/bottom border via BorderStyle)
            return "        <y:ShapeNode>\n"
                 . "          <y:Geometry height=\"{$h}\" width=\"{$w}\" x=\"{$x}\" y=\"{$y}\"/>\n"
                 . "          <y:Fill color=\"{$color}\" transparent=\"false\"/>\n"
                 . "          <y:BorderStyle color=\"#000000\" type=\"line\" width=\"2.0\"/>\n"
                 . "          <y:NodeLabel alignment=\"center\" fontStyle=\"plain\" modelName=\"internal\" modelPosition=\"c\">{$label}</y:NodeLabel>\n"
                 . "          <y:Shape type=\"rectangle\"/>\n"
                 . "        </y:ShapeNode>\n";
        }

        if ($shape['kind'] === 'diamond')
        {
            return "        <y:ShapeNode>\n"
                 . "          <y:Geometry height=\"{$h}\" width=\"{$w}\" x=\"{$x}\" y=\"{$y}\"/>\n"
                 . "          <y:Fill color=\"{$color}\" transparent=\"false\"/>\n"
                 . "          <y:BorderStyle color=\"#000000\" type=\"line\" width=\"1.0\"/>\n"
                 . "          <y:NodeLabel alignment=\"center\" modelName=\"internal\" modelPosition=\"c\">{$label}</y:NodeLabel>\n"
                 . "          <y:Shape type=\"diamond\"/>\n"
                 . "        </y:ShapeNode>\n";
        }

        if ($shape['kind'] === 'ellipse')
        {
            return "        <y:ShapeNode>\n"
                 . "          <y:Geometry height=\"{$h}\" width=\"{$w}\" x=\"{$x}\" y=\"{$y}\"/>\n"
                 . "          <y:Fill color=\"{$color}\" transparent=\"false\"/>\n"
                 . "          <y:BorderStyle color=\"#000000\" type=\"line\" width=\"1.0\"/>\n"
                 . "          <y:NodeLabel alignment=\"center\" modelName=\"internal\" modelPosition=\"c\">{$label}</y:NodeLabel>\n"
                 . "          <y:Shape type=\"ellipse\"/>\n"
                 . "        </y:ShapeNode>\n";
        }

        // default rect / roundrectangle
        $shapeType = $shape['shape'] ?? 'rectangle';
        return "        <y:ShapeNode>\n"
             . "          <y:Geometry height=\"{$h}\" width=\"{$w}\" x=\"{$x}\" y=\"{$y}\"/>\n"
             . "          <y:Fill color=\"{$color}\" transparent=\"false\"/>\n"
             . "          <y:BorderStyle color=\"#000000\" type=\"line\" width=\"1.0\"/>\n"
             . "          <y:NodeLabel alignment=\"center\" modelName=\"internal\" modelPosition=\"c\">{$label}</y:NodeLabel>\n"
             . "          <y:Shape type=\"{$shapeType}\"/>\n"
             . "        </y:ShapeNode>\n";
    }

    // -------------------------------------------------------------
    // Edge rendering
    // -------------------------------------------------------------

    private function RenderEdge(array $edge): string
    {
        static $counter = 0;
        $counter++;
        $eid = 'e' . $counter;

        $src = $this->XmlEsc($edge['source_node_key']);
        $tgt = $this->XmlEsc($edge['target_node_key']);

        $label = $edge['label'] ?? '';
        if ($this->diagramType === 'ERD' && !empty($edge['cardinality']))
        {
            $label = trim(($label ? $label . '  ' : '') . '[' . $edge['cardinality'] . ']');
        }
        $label = $this->XmlEsc($label);

        $style = $edge['edge_style'] ?? 'line';
        [$lineStyle, $targetArrow] = match ($style)
        {
            'dashed' => ['dashed', 'none'],
            'flow' => ['line', 'standard'],
            default => ['line', $this->diagramType === 'ERD' ? 'none' : 'standard'],
        };

        $out = "    <edge id=\"{$eid}\" source=\"{$src}\" target=\"{$tgt}\">\n";
        $out .= "      <data key=\"d10\">\n";
        $out .= "        <y:PolyLineEdge>\n";
        $out .= "          <y:LineStyle color=\"#000000\" type=\"{$lineStyle}\" width=\"1.0\"/>\n";
        $out .= "          <y:Arrows source=\"none\" target=\"{$targetArrow}\"/>\n";
        if ($label !== '')
        {
            $out .= "          <y:EdgeLabel alignment=\"center\" modelName=\"centered\" fontSize=\"10\">{$label}</y:EdgeLabel>\n";
        }
        $out .= "          <y:BendStyle smoothed=\"false\"/>\n";
        $out .= "        </y:PolyLineEdge>\n";
        $out .= "      </data>\n";
        $out .= "    </edge>\n";
        return $out;
    }

    private function XmlEsc(?string $v): string
    {
        return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
