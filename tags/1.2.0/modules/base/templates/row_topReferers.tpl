<TD class="item_cell"><a href="<?=$row['url'];?>"><? if (!empty($row['page_title'])):?><?=$this->truncate($row['page_title'], 70, '...');?><? else:?><?=$this->truncate($row['url'], 70, '...');?><? endif;?></a></TD>
<TD class="data_cell"><?=$row['count']?></TD>
