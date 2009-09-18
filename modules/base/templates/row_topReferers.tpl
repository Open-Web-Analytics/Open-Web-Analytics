<TD class="item_cell"><a href="<?php echo $row['url'];?>"><?php if (!empty($row['page_title'])):?><?php echo $this->truncate($row['page_title'], 70, '...');?><?php else:?><?php echo $this->truncate($row['url'], 70, '...');?><?php endif;?></a></TD>
<TD class="data_cell"><?php echo $row['count']?></TD>
