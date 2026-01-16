select distinct path
from files
where (:withdeleted != 'y' AND (deleted != 'y' OR deleted IS NULL))
   OR (:withdeleted = 'y' AND deleted = 'y')
