import os
import glob

files = glob.glob('src/pages/**/*.tsx', recursive=True)

for filepath in files:
    with open(filepath, 'r') as f:
        content = f.read()
        
    if 'Modal, ModalFooter' in content:
        # We need to fix the from path.
        # e.g. from '@/components/ui/Modal, ModalFooter' -> from '@/components/ui/Modal'
        # e.g. from '@/components/ui, ModalFooter' -> from '@/components/ui'
        
        content = content.replace("from '@/components/ui/Modal, ModalFooter'", "from '@/components/ui/Modal'")
        content = content.replace('from "@/components/ui/Modal, ModalFooter"', 'from "@/components/ui/Modal"')
        content = content.replace("from '@/components/ui, ModalFooter'", "from '@/components/ui'")
        content = content.replace('from "@/components/ui, ModalFooter"', 'from "@/components/ui"')
        
        with open(filepath, 'w') as f:
            f.write(content)
        print(f"Fixed {filepath}")
