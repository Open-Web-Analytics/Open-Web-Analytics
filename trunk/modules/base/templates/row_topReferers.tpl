<TD class="item_cell"><a href="<?=$row['url'];?>"><? if (!empty($row['page_title'])):?><?=$this->truncate($row['page_title'], 100, '...');?><? else:?><?=$this->truncate($row['url'], 100, '...');?><? endif;?></a></TD>
<TD class="data_cell"><?=$row['count']?></TD>
