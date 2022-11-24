# OpenContent Installer

Descrizione..

## installer.yml

```yaml
name: 'Product name'
version: 1.0.0
variables:
  - name: foo
    value: 'bar'
  - name: other_foo
    value: 'other beer'    
steps:
  - type: <step_type>
    identifier: my_step
    condition: true
    current_version_lt: 1.0.0
    current_version_le: 1.0.0
    current_version_eq: 1.0.0
    current_version_ge: 1.0.0
    current_version_gt: 1.0.0
    ignore_error: false
    my_step_parameter: ...
    my_step_other_parameter: ...
```

## Step type

### Stati
```yaml
  - type: state
    identifier: privacy
    
  - type: states
    identifiers:
      - moderation
      - privacy
       
  - type: change_state
    identifier: default 
```

### Sezioni
```yaml     
  - type: section
    identifier: intranet
    
  - type: sections
    identifiers:
      - intranet
      - restricted
  - type: change_section
    identifier: default      
```

### Classi
```yaml 
  - type: class
    identifier: folder
    
  - type: classextra
    identifier: pagina_sito
    
  - type: class_with_extra
    identifier: folder
```

### Contenuti
```yaml
  - type: contenttree
    identifier: ExampleData

  - type: content
    identifier: Example
    
  - type: patch_content    
    identifier: content_remote_id
    attributes:
      name: 'New patched name'
    sort_data:
      sort_field: 9
      sort_order: 1
      priority: 20
```

### Ruoli
```yaml
  - type: role
    identifier: Anonymous
    apply_to:
      - $anonymous_user
```

### Recaptcha
```yaml
  - type: openparecaptcha
    public: $recaptcha_public
    private: $recaptcha_private
    condition: $is_install_from_scratch
  - type: openparecaptcha_v3
    public: $recaptcha_v3_public
    private: $recaptcha_v3_private
    condition: $is_install_from_scratch  
```

### Workflow
```yaml
  - type: workflow
    identifier: Post-pubblicazione
    trigger:
      module: content
      function: publish
      connection_type: after
```

### Tag
```yaml      
  - type: tagtree_csv
    identifiers:
      - csv_filename_1_without_extension
      - csv_filename_2_without_extension

  - type: tagtree
    identifier: Tag-tree-example

  - type: add_tag    
    identifier: Example_Tag
    parent: tag(Path/To/Parent/Tag)
    tags:
      - keyword: Esempio
        locale: ita-IT
        alwaysAvailable: true
        synonyms: { }
        keywordTranslations:
          ita-IT: Esempio
          eng-GB: Example
          
  - type: remove_tag
    parent: 'tag(Path/To/Parent/Tag)'
    tags:
      - keyword: 'Tag obsoleto'
        locale: ita-IT
        alwaysAvailable: true
        synonyms: { }
        keywordTranslations:
          ita-IT: 'Tag obsoleto'

  - type: rename_tag
    tag: 'tag(Path/To/Tag/To/Raname)'
    keywords:
      ita-IT: 'Nuovo nome'
      
  - type: move_tag    
    new_parent: 'tag(Path/To/New/Parent)'
    tags:
      - keyword: 'Tag da spostare'
        locale: ita-IT
        alwaysAvailable: true
        synonyms: { }
        keywordTranslations:
          ita-IT: 'Tag da spostare'

  - type: tag_description
    identifier: Example
    root: 'tag(Path/To/Parent/Tag)'
    locale: ita-IT
```

### Sql
```yaml  
  - type: sql
    identifier: sql_filename
    
  - type: sql_copy_from_tsv
    identifier: tsv_filename_without_extension
    table: table_name        
```

### Reindicizzazione
```yaml            
  - type: reindex
    identifier: content_class_dentifier
```

### Argomenti deprecati
```yaml  
  - type: deprecate_topic
    identifier: topic_remote_id_1
    target: topic_remote_id_2_where_all_relations_are_remapped
    move_in: $parent_node_id
```