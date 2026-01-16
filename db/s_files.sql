select id,
       uid,
       name,
       path,
       fext,
       width,
       height,
       created,
       changed
from files
where (:withdeleted != 'y' AND (deleted != 'y' OR deleted IS NULL))
   OR (:withdeleted = 'y' AND deleted = 'y')
order by CASE
             WHEN changed IS NOT NULL THEN changed
             ELSE created
             END DESC
LIMIT :limit OFFSET :offset
